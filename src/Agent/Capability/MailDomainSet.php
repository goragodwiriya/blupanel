<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\DkimManager;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * Enables or disables mail for one domain — PLAN-MAIL Phase M1
 *
 * **Enabled per domain, never for the whole machine** — a domain not using mail
 * must never appear in `virtual_mailbox_domains` at all, because a domain listed
 * there means this machine has declared itself the final destination for that
 * domain's mail — nothing arriving there gets forwarded on anywhere else. Enable
 * it by mistake for a domain actually running on Gmail, and that customer's mail
 * vanishes into this machine entirely.
 *
 * Disabling mail never deletes a mailbox — the row stays in the database in case
 * it's re-enabled, but disappears from the real config file.
 */
final class MailDomainSet extends MailCapability
{
    public static function name(): string
    {
        return 'mail.domain_set';
    }

    public function summary(): string
    {
        return 'Enable or disable domain mail';
    }

    public function validate(array $args): array
    {
        return [
            'domain' => Validator::domain(Validator::requireString($args, 'domain', 253)),
            'enabled' => (bool) ($args['enabled'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if (!$this->mailboxes($context)->isInstalled($executor)) {
            throw new ValidationError(
                'Dovecot was not found on this machine — install it with apt install dovecot-imapd dovecot-pop3d dovecot-lmtpd first',
            );
        }

        $repository->setDomainMail((int) $domain['id'], $args['enabled']);

        $dkim = ['selector' => '', 'record' => '', 'created' => false];
        $records = [];
        $manager = new DkimManager(new Template($context->config->paths->templates()));

        if ($args['enabled']) {
            if ($manager->isInstalled($executor)) {
                $manager->apply($executor);
                $dkim = $manager->ensureKey($executor, (string) $domain['domain']);
            }

            // The records genuinely required to actually deliver mail — added
            // automatically if the domain uses this machine's DNS · for a domain
            // using DNS elsewhere, these are the values that need adding by hand
            $records = $this->ensureDnsRecords($context, $domain, $dkim);
        } else {
            $manager->removeKey($executor, (string) $domain['domain']);
        }

        $result = $this->sync($executor, $context);

        return $result + [
            'domain' => (string) $domain['domain'],
            'enabled' => $args['enabled'],
            'dkim' => $dkim,
            'dns_records' => $records,
            'message' => $args['enabled']
                ? sprintf('Enabled mail for %s — the MX record must also point at this machine', $domain['domain'])
                : sprintf('Disabled mail for %s — the mailbox still exists but no longer accepts mail', $domain['domain']),
        ];
    }

    /**
     * The DNS records that let this domain's mail actually reach its destination
     *
     * These four work together — missing any one of them and mail ends up in the trash:
     *   MX     says which machine this domain's mail is sent to
     *   SPF    says which machines are allowed to send mail as this domain
     *   DKIM   the public key used to verify the signature
     *   DMARC  tells the destination what to do when the two above fail
     *
     * Only written into the panel's own table — it only reaches the real BIND9
     * once `dns.enabled` is on · a domain using DNS elsewhere still gets these
     * values to place by hand, through the mail readiness page.
     *
     * @param array<string,mixed> $domain
     * @param array{selector:string,record:string,created:bool} $dkim
     * @return list<array{type:string,name:string,value:string}>
     */
    private function ensureDnsRecords(Context $context, array $domain, array $dkim): array
    {
        $host = trim((new SettingsRepository($context->db))->get('mail.hostname'));
        $name = (string) $domain['domain'];

        $wanted = [
            ['type' => 'MX', 'name' => '@', 'value' => $host !== '' ? $host . '.' : $name . '.'],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 a mx ~all'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $name],
        ];

        if ($dkim['record'] !== '') {
            $wanted[] = [
                'type' => 'TXT',
                'name' => $dkim['selector'] . '._domainkey',
                'value' => $dkim['record'],
            ];
        }

        foreach ($wanted as $record) {
            $exists = $context->db->value(
                'SELECT count(*) FROM dns_records WHERE domain_id = :d AND type = :t AND name = :n',
                ['d' => (int) $domain['id'], 't' => $record['type'], 'n' => $record['name']],
                0,
            );

            // Never overwrites an existing record — an admin may have already
            // set up SPF that includes another provider, and overwriting it
            // would instantly break verification for mail sent through there
            if ((int) $exists > 0) {
                continue;
            }

            $context->db->insert('dns_records', [
                'domain_id' => (int) $domain['id'],
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'ttl' => 3600,
                // This table has no timestamp column — a DNS record doesn't care who added it or when
                'priority' => $record['type'] === 'MX' ? 10 : null,
            ]);
        }

        return $wanted;
    }
}
