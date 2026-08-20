<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Kernel\Db;

/**
 * Mailboxes and forwarding addresses — PLAN-MAIL Phase M1
 *
 * **The main job here is assembling "the whole machine's picture" for the file
 * writer**, not just CRUD · Postfix's own lookup table gets fully rewritten every
 * time, so the writer must always receive the complete machine-wide list, not just
 * what recently changed — otherwise a mailbox that's already been deleted would
 * keep accepting mail, since it would still be sitting in the file.
 */
final class MailboxRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every domain with mail enabled
     *
     * @return list<string>
     */
    public function enabledDomains(): array
    {
        $rows = $this->db->all('SELECT domain FROM domains WHERE mail_enabled = 1 ORDER BY domain');

        return array_map(static fn (array $row): string => (string) $row['domain'], $rows);
    }

    /**
     * Every mailbox in the shape the file writer can use immediately
     *
     * A mailbox belonging to a domain whose mail has been turned off must never be
     * included — the row still stays in the database (in case it's turned back on)
     * but must have no effect on the machine while it's off.
     *
     * @return list<array{address:string,maildir:string,password:string,quota_mb:int}>
     */
    public function activeMailboxes(): array
    {
        $rows = $this->db->all(
            'SELECT m.local_part, m.password_hash, m.quota_mb, d.domain
               FROM mailboxes m
               JOIN domains d ON d.id = m.domain_id
              WHERE m.enabled = 1 AND d.mail_enabled = 1
              ORDER BY d.domain, m.local_part',
        );

        return array_map(
            static function (array $row): array {
                $address = new MailAddress((string) $row['local_part'], (string) $row['domain']);

                return [
                    'address' => $address->full(),
                    'maildir' => $address->maildir(MailboxManager::MAIL_ROOT),
                    'password' => (string) $row['password_hash'],
                    'quota_mb' => (int) $row['quota_mb'],
                ];
            },
            $rows,
        );
    }

    /**
     * Every forwarding address — an empty `source` means that domain's catch-all
     *
     * @return list<array{source:string,destination:string}>
     */
    public function activeAliases(): array
    {
        $rows = $this->db->all(
            'SELECT a.source, a.destination, d.domain
               FROM mail_aliases a
               JOIN domains d ON d.id = a.domain_id
              WHERE d.mail_enabled = 1
              ORDER BY d.domain, a.source',
        );

        return array_map(
            static fn (array $row): array => [
                // Postfix writes a catch-all as `@domain`, not `*@domain`
                'source' => ((string) $row['source'] === '' ? '' : (string) $row['source'])
                    . '@' . (string) $row['domain'],
                'destination' => (string) $row['destination'],
            ],
            $rows,
        );
    }

    /** @return array<string,mixed>|null */
    public function findDomain(string $domain): ?array
    {
        $row = $this->db->first('SELECT * FROM domains WHERE domain = :d', ['d' => strtolower(trim($domain))]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findMailbox(int $domainId, string $localPart): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM mailboxes WHERE domain_id = :d AND local_part = :l',
            ['d' => $domainId, 'l' => $localPart],
        );

        return is_array($row) ? $row : null;
    }

    /** Number of mailboxes this account owns — used against the `quota_emails` quota */
    public function countOwnedBy(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT count(*)
               FROM mailboxes m
               JOIN domains d ON d.id = m.domain_id
               JOIN sites s   ON s.id = d.site_id
              WHERE s.owner_user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    public function setDomainMail(int $domainId, bool $enabled): void
    {
        $this->db->update('domains', ['mail_enabled' => $enabled ? 1 : 0], ['id' => $domainId]);
    }

    public function createMailbox(int $domainId, string $localPart, string $passwordHash, int $quotaMb): int
    {
        return $this->db->insert('mailboxes', [
            'domain_id' => $domainId,
            'local_part' => $localPart,
            'password_hash' => $passwordHash,
            'quota_mb' => $quotaMb,
            'enabled' => 1,
            'created_at' => time(),
        ]);
    }

    public function setPassword(int $mailboxId, string $passwordHash): void
    {
        $this->db->update('mailboxes', ['password_hash' => $passwordHash], ['id' => $mailboxId]);
    }

    public function setQuota(int $mailboxId, int $quotaMb): void
    {
        $this->db->update('mailboxes', ['quota_mb' => $quotaMb], ['id' => $mailboxId]);
    }

    /** @return array<string,mixed>|null */
    public function findAlias(int $id): ?array
    {
        $row = $this->db->first(
            'SELECT a.*, d.domain FROM mail_aliases a JOIN domains d ON d.id = a.domain_id WHERE a.id = :id',
            ['id' => $id],
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Set a forwarding address — the same source on the same domain is an edit, not a duplicate add
     *
     * The table already has a unique constraint on (domain_id, source), so a
     * duplicate insert would fail · this updates instead, so a single form can
     * handle both adding and editing, the same as every other form in the system.
     */
    public function setAlias(int $domainId, string $source, string $destination): int
    {
        $existing = $this->db->first(
            'SELECT id FROM mail_aliases WHERE domain_id = :d AND source = :s',
            ['d' => $domainId, 's' => $source],
        );

        if (is_array($existing)) {
            $this->db->update('mail_aliases', ['destination' => $destination], ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('mail_aliases', [
            'domain_id' => $domainId,
            'source' => $source,
            'destination' => $destination,
            'created_at' => time(),
        ]);
    }

    public function deleteAlias(int $id): void
    {
        $this->db->run('DELETE FROM mail_aliases WHERE id = :id', ['id' => $id]);
    }

    /**
     * All of one owner's mailboxes and forwarding addresses — used to display on the web page
     *
     * `$ownerUserId` of 0 = an admin, sees everyone's · any other value = sees only
     * their own. The filtering happens in the query, not after fetching — another
     * customer's mailbox must never be read into memory in the first place.
     *
     * @return list<array<string,mixed>>
     */
    public function listMailboxes(int $ownerUserId = 0): array
    {
        $sql = 'SELECT m.id, m.local_part, m.quota_mb, m.enabled, m.created_at, d.domain, d.id AS domain_id
                  FROM mailboxes m
                  JOIN domains d ON d.id = m.domain_id
                  JOIN sites s   ON s.id = d.site_id';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' WHERE s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain, m.local_part', $params);
    }

    /** @return list<array<string,mixed>> */
    public function listAliases(int $ownerUserId = 0): array
    {
        $sql = 'SELECT a.id, a.source, a.destination, a.created_at, d.domain
                  FROM mail_aliases a
                  JOIN domains d ON d.id = a.domain_id
                  JOIN sites s   ON s.id = d.site_id';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' WHERE s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain, a.source', $params);
    }

    /**
     * Domains with mail already enabled that the caller owns — used to populate a form's options
     *
     * @return list<array<string,mixed>>
     */
    public function selectableDomains(int $ownerUserId = 0): array
    {
        $sql = 'SELECT d.id, d.domain
                  FROM domains d
                  JOIN sites s ON s.id = d.site_id
                 WHERE d.mail_enabled = 1';
        $params = [];

        if ($ownerUserId > 0) {
            $sql .= ' AND s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain', $params);
    }

    /**
     * The DNS records a mail-enabled domain needs, as the panel already wrote them
     *
     * Read straight out of `dns_records` rather than recomputed, because
     * {@see \Phpcp\Agent\Capability\MailDomainSet} already put them there the
     * moment mail was turned on for the domain — recomputing would risk showing
     * the customer a DKIM key different from the one rspamd actually signs with.
     *
     * **Safe to show a customer**, unlike the readiness table: MX/SPF/DMARC and
     * the DKIM *public* key are values that exist to be published in DNS · a
     * customer whose DNS is hosted elsewhere cannot receive mail at all without
     * them, and today the panel gives them nowhere to read them from.
     *
     * @return list<array<string,mixed>>
     */
    public function mailDnsRecords(int $ownerUserId = 0): array
    {
        $sql = 'SELECT d.domain, r.type, r.name, r.value, r.priority
                  FROM dns_records r
                  JOIN domains d ON d.id = r.domain_id
                  JOIN sites s   ON s.id = d.site_id
                 WHERE d.mail_enabled = 1
                   AND (r.type = :mx OR (r.type = :txt AND (r.name = :root OR r.name = :dmarc OR r.name LIKE :dkim)))';
        $params = [
            'mx' => 'MX',
            'txt' => 'TXT',
            'root' => '@',
            'dmarc' => '_dmarc',
            'dkim' => '%._domainkey',
        ];

        if ($ownerUserId > 0) {
            $sql .= ' AND s.owner_user_id = :o';
            $params['o'] = $ownerUserId;
        }

        return $this->db->all($sql . ' ORDER BY d.domain, r.type, r.name', $params);
    }

    /** Who owns this domain — used to check permission before touching the domain's mailboxes */
    public function ownerOf(int $domainId): int
    {
        return (int) $this->db->value(
            'SELECT s.owner_user_id FROM domains d JOIN sites s ON s.id = d.site_id WHERE d.id = :d',
            ['d' => $domainId],
            0,
        );
    }

    public function deleteMailbox(int $mailboxId): void
    {
        $this->db->run('DELETE FROM mailboxes WHERE id = :id', ['id' => $mailboxId]);
    }
}
