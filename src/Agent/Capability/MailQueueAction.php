<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;
use Phpcp\Support\Validator;

/**
 * Acts on the outbound mail queue — PLAN-MAIL §5
 *
 * The three commands an admin genuinely needs when mail is stuck:
 *
 *   flush       retry sending right now — used after fixing the root cause (DNS
 *               fixed, relay back up), without waiting for Postfix's next cycle,
 *               which gets further apart with every failed attempt
 *   delete      remove one message — a misdirected email, or spam that got shoved into the queue
 *   delete_all  clear the whole queue — **deletes every customer's message still waiting to send**
 *
 * `delete_all` must be sent as its own explicit value, never `delete` with an id
 * of `ALL` — see the reasoning at `MailQueue::deleteAll()` · getting this wrong
 * here means every customer's mail vanishes with nobody the wiser.
 */
final class MailQueueAction implements Capability
{
    public static function name(): string
    {
        return 'mail.queue_action';
    }

    /** The queue belongs to the whole machine — see the reasoning at MailQueueList */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Act on outbound mail queue';
    }

    public function validate(array $args): array
    {
        $action = Validator::requireEnum($args, 'action', ['flush', 'delete', 'delete_all', 'release']);
        $id = trim((string) ($args['id'] ?? ''));

        // The id is validated right here for commands that need it — `ALL` is rejected inside assertId
        if (in_array($action, ['delete', 'release'], true)) {
            $id = MailQueue::assertId($id);
        }

        return ['action' => $action, 'id' => $id];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $queue = new MailQueue();

        $message = match ($args['action']) {
            'flush' => $this->flush($queue, $executor),
            'delete' => $this->delete($queue, $executor, $args['id']),
            'release' => $this->release($queue, $executor, $args['id']),
            default => $this->deleteAll($queue, $executor),
        };

        return [
            'action' => $args['action'],
            'id' => $args['id'],
            'remaining' => $queue->list($executor)['total'],
            'message' => $message,
        ];
    }

    private function flush(MailQueue $queue, Executor $executor): string
    {
        $queue->flush($executor);

        return 'Retrying every message in the queue now — anything still undeliverable will return to the queue with a new reason';
    }

    private function delete(MailQueue $queue, Executor $executor, string $id): string
    {
        $queue->delete($executor, $id);

        return sprintf('Removed message %s from the queue', $id);
    }

    private function release(MailQueue $queue, Executor $executor, string $id): string
    {
        $queue->release($executor, $id);

        return sprintf('Released message %s back into the normal queue', $id);
    }

    private function deleteAll(MailQueue $queue, Executor $executor): string
    {
        $before = $queue->list($executor)['total'];

        $queue->deleteAll($executor);

        return sprintf('Cleared the queue — deleted %d waiting message(s), senders were not notified', $before);
    }
}
