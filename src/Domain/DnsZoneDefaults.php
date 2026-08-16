<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * The first set of records a domain needs before its zone actually works
 *
 * ## The problem this fixes
 *
 * Creating a site used to produce zero DNS records · the admin got a domain with an
 * empty DNS page, had to type every line themselves, and **the zone file never got
 * generated at all** until the first record was added — unlike cPanel/DirectAdmin/Plesk,
 * where creating a domain produces a working zone immediately.
 *
 * ## The glue record — miss it and the whole zone breaks
 *
 * `dns.nameservers` is usually set to a name **inside the domain itself**
 * (`ns1.example.com` for the zone `example.com`) · when the nameserver's own name
 * lives under the zone it manages, that zone must contain an A record for that name
 * too, otherwise nobody can find its address (a chicken-and-egg problem) ·
 * `named-checkzone` rejects it outright with *"NS 'ns1.example.com' has no address
 * records"*, and **the entire zone fails to load**.
 *
 * Found on a real server, 2026-08-14: the machine's very first zone failed to write
 * for exactly this reason.
 *
 * ## What's deliberately not included
 *
 * No MX — a domain that hasn't turned mail on shouldn't announce that it accepts
 * mail, since incoming mail would then bounce permanently instead of reaching the
 * customer's actual mail server · `MailDomainSet` adds it once mail is actually turned on.
 */
final class DnsZoneDefaults
{
    /** A short initial TTL so a mistake propagates quickly — the admin can raise it later */
    public const TTL = 3600;

    /**
     * The records a domain should have
     *
     * @param  list<string> $nameservers nameserver names from the system's settings
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:null}>
     */
    public static function forDomain(string $domain, string $ip, array $nameservers): array
    {
        if (!ServerAddress::isIpv4($ip)) {
            return [];
        }

        $names = ['@', 'www'];

        // Glue for any nameserver that lives under this zone — see the class docblock for why
        foreach ($nameservers as $ns) {
            $label = self::labelInside($ns, $domain);

            if ($label !== null && !in_array($label, $names, true)) {
                $names[] = $label;
            }
        }

        return array_map(
            static fn (string $name): array => [
                'type' => 'A',
                'name' => $name,
                'value' => $ip,
                'ttl' => self::TTL,
                'priority' => null,
            ],
            $names,
        );
    }

    /**
     * Write records that don't already exist into the database — safe to call repeatedly, never duplicates
     *
     * Skips any name that already has a record of **any type**, not just the same
     * type · an admin who's already set `www` to a CNAME must not silently get an A
     * record layered on top, which would make the zone self-contradictory (a CNAME
     * can't coexist with another record type at the same name, per the standard).
     *
     * @param  list<string> $nameservers
     * @return list<string> names that were just created
     */
    public static function seed(Db $db, int $domainId, string $domain, string $ip, array $nameservers): array
    {
        $existing = array_column(
            $db->all('SELECT name FROM dns_records WHERE domain_id = :id', ['id' => $domainId]),
            'name',
        );

        $wanted = self::forDomain($domain, $ip, $nameservers);

        /*
         * A domain this machine already serves, and that lives **under** the zone
         * being created, must have its name present in this zone from the very first
         * second.
         *
         * **Found on a real server, 2026-08-14:** the machine was already serving
         * `srv.example.com`, relying on a record set at the domain registrar · once
         * the admin created a site for `example.com` and pointed its NS here, the new
         * zone had no `srv` entry at all — the already-working site became NXDOMAIN
         * immediately, and certbot could no longer renew its certificate, with
         * nothing warning about it.
         *
         * Taking over a zone must never make something this machine is already
         * serving disappear.
         */
        foreach ($db->all('SELECT domain FROM domains WHERE id <> :id', ['id' => $domainId]) as $row) {
            $label = self::labelInside((string) $row['domain'], $domain);

            if ($label === null || $label === '@') {
                continue;
            }

            foreach (self::forSubdomain($label, $ip) as $record) {
                $wanted[] = $record;
            }
        }

        $created = [];

        foreach ($wanted as $record) {
            if (in_array($record['name'], $existing, true)) {
                continue;
            }

            $db->insert('dns_records', ['domain_id' => $domainId] + $record);
            $created[] = $record['name'];
        }

        return $created;
    }

    /**
     * The zone that **should** hold this domain's records — null = this domain is its own zone
     *
     * ## Why this exists
     *
     * Two sites related by name (`example.com` and `srv.example.com`) are stored as
     * two equal rows in `domains` · but in DNS terms they're not equal at all: once
     * this machine owns the zone `example.com`, **the name `srv.example.com` must be
     * a record living inside that zone**, not a separate zone file of its own (unless
     * it's deliberately delegated out, which is a different job requiring an explicit
     * NS record).
     *
     * **Found on a real server, 2026-08-14:** the machine already had
     * `srv.example.com` serving traffic, relying on a record set at the domain
     * registrar · once the admin created a site for `example.com` and pointed its NS
     * here, the new zone had no `srv` entry at all — `srv.example.com` became
     * NXDOMAIN immediately, the already-working site went down with no warning, and
     * certbot could no longer renew its certificate at all because it could no longer
     * pass authorization.
     *
     * Picks the longest zone that covers this name — a machine hosting both
     * `example.com` and `sub.example.com` as genuinely separate zones must get the
     * nearest one as the owner.
     *
     * @return array{id:int,domain:string,label:string}|null
     */
    public static function parentZone(Db $db, string $domain, int $exceptDomainId = 0): ?array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $best = null;

        foreach ($db->all('SELECT id, domain FROM domains') as $row) {
            $candidate = strtolower(rtrim((string) $row['domain'], '.'));

            if ((int) $row['id'] === $exceptDomainId || $candidate === $domain) {
                continue;
            }

            if (!str_ends_with($domain, '.' . $candidate)) {
                continue;
            }

            if ($best === null || strlen($candidate) > strlen($best['domain'])) {
                $best = [
                    'id' => (int) $row['id'],
                    'domain' => $candidate,
                    'label' => substr($domain, 0, -strlen('.' . $candidate)),
                ];
            }
        }

        return $best;
    }

    /**
     * Records for a name that lives **under someone else's zone** — used instead of
     * forDomain() in that case
     *
     * Produces `srv` and `www.srv`, the equivalent of `@` and `www` in a normal zone,
     * so the admin sees the same behavior no matter which level the domain sits at.
     *
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:null}>
     */
    public static function forSubdomain(string $label, string $ip): array
    {
        if (!ServerAddress::isIpv4($ip) || trim($label) === '') {
            return [];
        }

        return array_map(
            static fn (string $name): array => [
                'type' => 'A',
                'name' => $name,
                'value' => $ip,
                'ttl' => self::TTL,
                'priority' => null,
            ],
            [$label, 'www.' . $label],
        );
    }

    /**
     * Write a subdomain's records into the parent zone — safe to call repeatedly
     *
     * @return list<string>
     */
    public static function seedSubdomain(Db $db, int $parentDomainId, string $label, string $ip): array
    {
        $existing = array_column(
            $db->all('SELECT name FROM dns_records WHERE domain_id = :id', ['id' => $parentDomainId]),
            'name',
        );

        $created = [];

        foreach (self::forSubdomain($label, $ip) as $record) {
            if (in_array($record['name'], $existing, true)) {
                continue;
            }

            $db->insert('dns_records', ['domain_id' => $parentDomainId] + $record);
            $created[] = $record['name'];
        }

        return $created;
    }

    /**
     * The part of `$ns` in front, when it lives under `$domain` — null when it's in a different zone
     *
     * `ns1.example.com` under `example.com` → `ns1`
     * `ns1.other.com`   under `example.com` → null (different zone, no glue needed)
     * `example.com`     under `example.com` → `@`
     */
    private static function labelInside(string $ns, string $domain): ?string
    {
        $ns = strtolower(rtrim(trim($ns), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($ns === '' || $domain === '') {
            return null;
        }

        if ($ns === $domain) {
            return '@';
        }

        return str_ends_with($ns, '.' . $domain)
            ? substr($ns, 0, -strlen('.' . $domain))
            : null;
    }
}
