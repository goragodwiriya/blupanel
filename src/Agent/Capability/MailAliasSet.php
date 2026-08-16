<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Support\Validator;

/**
 * Forwarding addresses — alias, forwarder, and catch-all (PLAN-MAIL Phase M2)
 *
 * These three are the same mechanism as far as Postfix is concerned, differing
 * only in the value entered:
 *
 *   alias      `sales@example.com` → `somchai@example.com`  (a mailbox on the same machine)
 *   forwarder  `sales@example.com` → `someone@gmail.com`    (going out externally)
 *   catch-all  no name specified → catches every name that doesn't match a mailbox or other alias on that domain
 *
 * **catch-all is a double-edged sword** — it does catch mail with a misspelled
 * name, but it also catches spam blasted at every name at random, which is how
 * spammers discover a domain's real addresses.
 */
final class MailAliasSet extends MailCapability
{
    public static function name(): string
    {
        return 'mail.alias_set';
    }

    public function summary(): string
    {
        return 'Set forwarding address or catch-all';
    }

    public function validate(array $args): array
    {
        $domain = Validator::domain(Validator::requireString($args, 'domain', 253));
        $source = trim((string) ($args['source'] ?? ''));

        // Empty = this domain's catch-all · a value = must be a valid mailbox name
        if ($source !== '') {
            $source = MailAddress::assertLocalPart($source);
        }

        $destinations = [];

        foreach (preg_split('/[\s,]+/', (string) ($args['destination'] ?? '')) ?: [] as $one) {
            $one = trim($one);

            if ($one === '') {
                continue;
            }

            // A destination is always a full address, whether local or external
            $destinations[] = MailAddress::parse($one)->full();
        }

        if ($destinations === []) {
            throw new ValidationError('At least one destination address must be specified');
        }

        if (count($destinations) > 20) {
            throw new ValidationError('No more than 20 destinations are allowed per entry');
        }

        return ['domain' => $domain, 'source' => $source, 'destination' => implode(',', $destinations)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if ((int) ($domain['mail_enabled'] ?? 0) !== 1) {
            throw new ValidationError('Domain ' . $args['domain'] . ' does not have mail enabled yet');
        }

        // A name that already has a mailbox must never be crowded out by an alias
        // — Postfix always reads virtual_alias_maps before virtual_mailbox_maps,
        // so mail would never reach that mailbox again
        if ($args['source'] !== '' && $repository->findMailbox((int) $domain['id'], $args['source']) !== null) {
            throw new ValidationError(
                'Mailbox ' . $args['source'] . '@' . $args['domain'] . ' already exists — it cannot be set as a'
                . ' forwarding address, since mail would be forwarded elsewhere instead of reaching the mailbox',
            );
        }

        $id = $repository->setAlias((int) $domain['id'], $args['source'], $args['destination']);

        $result = $this->sync($executor, $context);
        $label = ($args['source'] === '' ? '@' : $args['source'] . '@') . $args['domain'];

        return $result + [
            'id' => $id,
            'source' => $label,
            'destination' => $args['destination'],
            'message' => sprintf('Set %s to forward to %s', $label, $args['destination']),
        ];
    }
}
