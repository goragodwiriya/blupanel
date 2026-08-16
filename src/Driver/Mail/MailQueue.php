<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Postfix's outbound mail queue — PLAN-MAIL §5 (`mail.queue`)
 *
 * ## Why the queue is the place to look when mail doesn't arrive
 *
 * Mail that fails to send doesn't go anywhere — it sits in the queue with
 * **a reason per message** that the destination sent back ("Connection
 * refused", "550 spam", "Recipient address rejected") · Postfix keeps
 * retrying on its own for several days before giving up, so an admin has
 * time to see it and fix it.
 *
 * Without this screen, the only way to answer "why didn't my mail arrive"
 * is to ssh in and type `postqueue -p`, which means anyone without ssh access can't answer it at all.
 *
 * ## Mail sitting in the queue has never been delivered into anyone's mailbox
 *
 * So viewing a queued message's content never touches anyone's mailbox, and
 * there's no "mark as read" to worry about — unlike opening a Maildir, which is an entirely separate matter.
 */
final class MailQueue
{
    private const POSTQUEUE = '/usr/sbin/postqueue';
    private const POSTSUPER = '/usr/sbin/postsuper';
    private const POSTCAT = '/usr/sbin/postcat';

    /**
     * The full list of everything in the queue
     *
     * `postqueue -j` returns one JSON object per line (Postfix 3.1+) · an
     * older machine has no such option — this returns `available: false` so
     * the screen can say so directly, better than showing an empty queue
     * that reads as "nothing is stuck" when the truth is "cannot be viewed".
     *
     * @return array{available:bool,messages:list<array<string,mixed>>,total:int,deferred:int,oldest:int}
     */
    public function list(Executor $executor): array
    {
        $result = $executor->exec([$executor->path(self::POSTQUEUE), '-j'], timeout: 30);

        if (!$result->ok()) {
            return ['available' => false, 'messages' => [], 'total' => 0, 'deferred' => 0, 'oldest' => 0];
        }

        $messages = [];
        $deferred = 0;
        $oldest = 0;
        $now = time();

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (!is_array($row) || !isset($row['queue_id'])) {
                continue;
            }

            $queue = (string) ($row['queue_name'] ?? '');
            $arrived = (int) ($row['arrival_time'] ?? 0);

            if ($queue === 'deferred') {
                $deferred++;
            }

            if ($arrived > 0) {
                $oldest = $oldest === 0 ? $arrived : min($oldest, $arrived);
            }

            $recipients = [];
            $reason = '';

            foreach ((array) ($row['recipients'] ?? []) as $recipient) {
                $recipients[] = (string) ($recipient['address'] ?? '');

                // This message's reason = the first one found · several
                // recipients failing for different reasons is very rare,
                // and showing one reason still beats showing none at all
                if ($reason === '' && ($recipient['delay_reason'] ?? '') !== '') {
                    $reason = (string) $recipient['delay_reason'];
                }
            }

            $messages[] = [
                'id' => (string) $row['queue_id'],
                'queue' => $queue,
                'sender' => (string) ($row['sender'] ?? ''),
                'recipients' => $recipients,
                'size' => (int) ($row['message_size'] ?? 0),
                'arrival_time' => $arrived,
                'age_seconds' => $arrived > 0 ? max(0, $now - $arrived) : 0,
                'reason' => $reason,
            ];
        }

        return [
            'available' => true,
            'messages' => $messages,
            'total' => count($messages),
            'deferred' => $deferred,
            'oldest' => $oldest,
        ];
    }

    /** Tells Postfix to try sending every message in the queue right now, without waiting for the next cycle */
    public function flush(Executor $executor): void
    {
        $result = $executor->exec([$executor->path(self::POSTQUEUE), '-f'], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to flush the queue: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /** Deletes a single message from the queue — it's gone permanently, with no notice sent to the sender */
    public function delete(Executor $executor, string $id): void
    {
        $this->postsuper($executor, '-d', self::assertId($id));
    }

    /**
     * Deletes the entire queue — its own separate method, **never `delete('ALL')`**
     *
     * `postsuper -d ALL` clears the whole machine's queue · if this shared
     * the same path as deleting a single message, an `ALL` value leaking in
     * from a parameter (or a caller's typo) would delete every customer's
     * waiting mail instantly, with every validation gate seeing it as a
     * perfectly valid id.
     */
    public function deleteAll(Executor $executor): void
    {
        $this->postsuper($executor, '-d', 'ALL');
    }

    /** Releases a held message back into the normal queue */
    public function release(Executor $executor, string $id): void
    {
        $this->postsuper($executor, '-H', self::assertId($id));
    }

    /**
     * A single queued message's content — headers and body
     *
     * Truncated, because a large base64-encoded attachment is no help at
     * all for troubleshooting, but can genuinely make the response large enough to slow the browser down.
     */
    public function message(Executor $executor, string $id, int $limit = 60000): string
    {
        $result = $executor->exec(
            [$executor->path(self::POSTCAT), '-q', self::assertId($id)],
            timeout: 30,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'Failed to read this message — it may have already been sent or deleted: '
                . trim($result->stderr ?: $result->stdout),
            );
        }

        return mb_substr($result->stdout, 0, $limit);
    }

    private function postsuper(Executor $executor, string $flag, string $target): void
    {
        $result = $executor->exec(
            [$executor->path(self::POSTSUPER), $flag, $target],
            timeout: 60,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Mail queue command failed: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /**
     * A queue id must be a genuine id, nothing else
     *
     * **`ALL` must be rejected here** — it would sail right through an
     * alphanumeric check, but to `postsuper` it means "delete the entire
     * queue" · deleting the whole queue has its own separate method that a
     * caller has to deliberately call instead.
     */
    public static function assertId(string $id): string
    {
        $id = trim($id);

        if (strtoupper($id) === 'ALL') {
            throw new ValidationError('The id of the specific message must be given — clearing the whole queue is a separate command');
        }

        // Postfix's ids are hexadecimal, or base52 when long queue ids are turned on
        if (preg_match('/^[A-Za-z0-9]{4,40}$/', $id) !== 1) {
            throw new ValidationError('Invalid queue message id: ' . $id);
        }

        return $id;
    }
}
