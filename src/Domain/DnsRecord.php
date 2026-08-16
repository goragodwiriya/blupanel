<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * Rules for a single DNS record row — the one place that answers what values are valid
 *
 * Split out from the controller because there are now two paths that add records
 * (the legacy web page and the REST API). Leaving each path to validate on its own
 * guarantees a day when one accepts a value the other rejects, and the user hits
 * "works when added through the web page but not through the API" — one of the
 * hardest bugs to explain.
 *
 * Scope per ARCHITECTURE §15 Q1: this table stores "the intended value" and exports
 * it as a zone file — until Phase E3 actually connects BIND9, edits here don't yet
 * go through the agent.
 */
final class DnsRecord
{
    /**
     * Types the system "recognizes" — have dedicated validation and a form field
     *
     * **Not a closed list of what can be stored** (see {@see assertType()}) — it's the
     * list of types the system can validate more thoroughly than usual, e.g. A must be
     * IPv4, or CNAME must not point at an IP · types outside this list are stored
     * normally, with `named-checkzone` as the real arbiter of correctness.
     *
     * @var list<string>
     */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS', 'SRV'];

    /**
     * Types whose value is a "single hostname" — need a trailing dot added when the file is written
     *
     * Doesn't include SRV, even though it ends with a hostname, because its value has
     * four parts (`priority weight port target`) — appending a trailing dot to the
     * whole thing would produce `5060 sip.example.com.` in the wrong spot, so SRV
     * keeps its value as the whole line as written, like CAA.
     *
     * @var list<string>
     */
    public const HOSTNAME_TYPES = ['CNAME', 'MX', 'NS'];

    /** Beyond this, it's no longer a single domain's zone — guards against pasting data into the wrong field */
    public const MAX_RECORDS = 500;

    /**
     * TTL bounds — wide enough to cover every real-world use
     *
     * `0` is used in practice for records that change often (dynamic DNS, failover),
     * and one week is what major providers set for records that never change · the
     * old bounds (60–86400) silently cut off both cases, since the value was clamped
     * instead of rejected.
     */
    public const TTL_MIN = 0;
    public const TTL_MAX = 604800;

    /**
     * Maximum value length — a 2048-bit DKIM key runs past 512 characters once quotes
     * are included, and silent truncation makes the key unusable with nothing to flag it.
     */
    public const VALUE_MAX = 4096;

    /**
     * Maximum length of one character-string (RFC 1035 §3.3.14) — longer than this,
     * BIND rejects the whole file, so a TXT value longer than this must be split into
     * multiple chunks when it's written ({@see txtCharacterStrings()}), not when it's
     * received — the value the user enters stays a single value.
     */
    public const TXT_STRING_MAX = 255;

    /**
     * Validate what the user submitted and return a row ready to write to the database
     *
     * @param array<string,mixed> $input
     * @return array{type:string,name:string,value:string,ttl:int,priority:int|null}
     */
    public static function validate(array $input): array
    {
        $type = self::assertType((string) ($input['type'] ?? ''));

        $name = Validator::pattern(
            trim((string) ($input['name'] ?? '')) ?: '@',
            // @ = the domain itself · * = wildcard (alone, or leading a subdomain, e.g. *.dev)
            // A leading underscore is allowed because SRV/DMARC/DKIM use it (_sip._tcp, _dmarc, _domainkey)
            '/^(@|(\*|[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*)$/i',
            'Invalid record name',
        );

        $value = self::assertRdata(
            Validator::requireString(['value' => trim((string) ($input['value'] ?? ''))], 'value', self::VALUE_MAX),
        );

        $ttl = (int) ($input['ttl'] ?? 3600);
        $ttl = max(self::TTL_MIN, min(self::TTL_MAX, $ttl));

        // An MX with no priority is a zone file that can't be used — always fill in a default.
        $priority = null;
        if ($type === 'MX') {
            $priority = max(0, min(65535, (int) ($input['priority'] ?? 10)));
        }

        self::assertValueMatchesType($type, $value);

        return ['type' => $type, 'name' => $name, 'value' => $value, 'ttl' => $ttl, 'priority' => $priority];
    }

    /**
     * The record type name that's accepted — **a shape, not a list of names**
     *
     * ## Why not a closed list
     *
     * A closed list always falls behind, and does so silently · two types seen every
     * day in real hosting work — SRV (Microsoft 365, Teams, SIP) and NS (delegating a
     * subdomain to another DNS server) — weren't in the original list, and the list
     * will keep falling behind: TLSA, SSHFP, DS, HTTPS/SVCB, NAPTR, PTR · every time
     * someone hits a missing type, they have to wait for new code, which is too
     * expensive for "write three words into a file BIND already reads."
     *
     * The real arbiter of correctness is the **actual `named-checkzone` binary**, which
     * is always more accurate than any list we could hand-write, and is the same one
     * BIND uses when it actually loads the zone — the same principle this project
     * already applies to web server config files.
     *
     * So this only blocks what BIND's own checker can't: text that isn't a type name
     * at all · `TYPE65535` is the RFC 3597 format for a type that doesn't have a name yet.
     */
    public static function assertType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (preg_match('/^[A-Z][A-Z0-9]{0,14}$/', $type) !== 1) {
            throw new ValidationError(
                'Invalid record type — must be letters and digits, e.g. A, MX, SRV, TLSA',
            );
        }

        return $type;
    }

    /**
     * A record's value must not contain control characters — **the most important
     * guard for accepting every type**
     *
     * The value is written into a file BIND reads · being able to insert a newline
     * means being able to inject extra records, or inject `$INCLUDE` to make BIND read
     * another file on the machine — a hole that needs no unusual record type at all,
     * just a TXT value containing `\n`.
     *
     * `named-checkzone` already catches nearly all of this itself (and the system then
     * restores the previous file), but relying only on the downstream checker means a
     * dangerous value gets written to disk once for every attempt · blocking it at the
     * source is far cheaper.
     */
    public static function assertRdata(string $value): string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new ValidationError(
                'Record value contains control characters or a newline — '
                . 'a record must be a single line',
            );
        }

        return $value;
    }

    /**
     * The value must match the record's type
     *
     * Putting an IP into a CNAME field is the single most common mistake, and it's the
     * kind DNS servers accept silently, breaking that name across the entire domain —
     * it has to be caught at entry time.
     *
     * **This used to be a real bug:** the old validator used `/^[a-z0-9.-]+\.?$/i`,
     * which let "203.0.113.10" straight through since it's all digits and dots — a
     * comment claimed this was already guarded against, but the code didn't actually
     * do it. It now explicitly rejects a value that's an IP first, then checks the
     * hostname shape.
     */
    public static function assertValueMatchesType(string $type, string $value): void
    {
        match ($type) {
            'A' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? null
                : throw new ValidationError('An A record must be IPv4'),
            'AAAA' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? null
                : throw new ValidationError('An AAAA record must be IPv6'),
            'CNAME', 'MX', 'NS' => self::assertHostname($type, $value),
            'SRV' => self::assertSrv($value),
            // A type the system doesn't specifically recognize — named-checkzone is the arbiter
            default => null,
        };
    }

    private static function assertHostname(string $type, string $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            throw new ValidationError(
                "A {$type} record must be a hostname, not an IP address"
                . ($type === 'CNAME' ? ' — use an A or AAAA record instead if you want to point at an IP' : ''),
            );
        }

        // Hostname: each label starts and ends with a letter or digit, hyphens allowed
        // in between, may end with a dot (fully qualified), and needs at least one dot.
        $pattern = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\.?$/i';

        if (preg_match($pattern, $value) !== 1) {
            throw new ValidationError("A {$type} record must be a valid hostname, e.g. mail.example.com");
        }
    }

    /**
     * SRV packs four parts into a single value: `priority weight port target`
     *
     * Kept whole in the `value` column instead of split into four new columns —
     * because every other type this system accepts has its own different structure
     * (TLSA has three, NAPTR has six) · chasing after per-type columns is just a closed
     * list wearing a different shape.
     *
     * Only the first three numeric parts and the port range get validated, because
     * that's the mistake people actually make when typing (swapping weight and port),
     * and it's the kind BIND accepts silently.
     */
    private static function assertSrv(string $value): void
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        if (count($parts) !== 4) {
            throw new ValidationError(
                'An SRV record needs four parts: priority, weight, port, and target server, '
                . 'e.g. `0 5 5060 sip.example.com.`',
            );
        }

        foreach (array_slice($parts, 0, 3) as $index => $number) {
            if (preg_match('/^\d+$/', $number) !== 1 || (int) $number > 65535) {
                throw new ValidationError(sprintf(
                    'SRV record: part %d (%s) must be a number 0–65535',
                    $index + 1,
                    $number,
                ));
            }
        }

        self::assertHostname('SRV', rtrim($parts[3], '.'));
    }

    /**
     * Parse a zone file back into records — the reverse of {@see toAuthoritativeZoneFile()}
     *
     * ## Why parse it back, instead of editing the file directly
     *
     * The zone file gets fully regenerated from the database every time anyone touches
     * a single record · allowing direct file edits would be the most classic trap a
     * panel like this can set: the edit works immediately, everything looks correct,
     * then one day it silently disappears the next time someone adds a different record.
     *
     * Parsing edits back into the database makes "editing the file" behave the same way
     * from the user's point of view, while the database stays the single source of
     * truth — nothing is lost later, and the table view and the file always agree.
     *
     * ## What's not accepted, and why
     *
     * `$INCLUDE` tells BIND to read another file on the machine · `$ORIGIN` changes the
     * meaning of every name that follows it · `$GENERATE` produces a batch of records —
     * none of the three can be converted back into database rows, and accepting them
     * halfway is more dangerous than rejecting them with a reason.
     *
     * `SOA` and `NS` are **skipped silently**, not rejected — the panel always
     * generates both from the machine's own settings, and they're already present in
     * the file the user is editing · forcing them to be deleted before saving would
     * create work that helps nobody.
     *
     * @return list<array{type:string,name:string,value:string,ttl:int,priority:int|null}>
     * @throws ValidationError always includes a line number — a bare "invalid format"
     *         message forces the user to hunt through 50 lines themselves
     */
    public static function parseZoneFile(string $domain, string $text): array
    {
        $origin = strtolower(rtrim(trim($domain), '.'));
        $lines = preg_split('/\R/', $text) ?: [];

        $records = [];
        $defaultTtl = 3600;
        $owner = '@';

        // A single record can span multiple lines via parentheses — the panel's own
        // generated SOA does exactly that.
        $pending = '';
        $pendingLine = 0;
        $depth = 0;

        foreach ($lines as $index => $raw) {
            $lineNo = $index + 1;
            $clean = self::stripZoneComment($raw);

            if ($depth === 0 && trim($clean) === '') {
                continue;
            }

            // A line starting with whitespace reuses the previous record's name (BIND's own rule)
            if ($depth === 0 && preg_match('/^\s/', $raw) === 1) {
                $clean = $owner . ' ' . ltrim($clean);
            }

            if ($depth === 0) {
                $pendingLine = $lineNo;
            }

            $depth += substr_count($clean, '(') - substr_count($clean, ')');
            $pending = trim($pending . ' ' . str_replace(['(', ')'], ' ', $clean));

            if ($depth > 0) {
                continue;
            }

            $depth = 0;
            $statement = $pending;
            $pending = '';

            if ($statement === '') {
                continue;
            }

            $tokens = self::tokenizeZoneLine($statement);

            if ($tokens === []) {
                continue;
            }

            if (str_starts_with($tokens[0], '$')) {
                $defaultTtl = self::applyZoneDirective($tokens, $defaultTtl, $pendingLine);
                continue;
            }

            $owner = $tokens[0];
            $record = self::zoneStatementToRecord(array_slice($tokens, 1), $owner, $origin, $defaultTtl, $pendingLine);

            if ($record !== null) {
                $records[] = $record;
            }

            if (count($records) > self::MAX_RECORDS) {
                throw new ValidationError(sprintf(
                    'More than %d records — this many is usually a sign of pasted data landing in the wrong place',
                    self::MAX_RECORDS,
                ));
            }
        }

        if ($depth !== 0) {
            throw new ValidationError(sprintf('Line %d: an opening parenthesis was never closed', $pendingLine));
        }

        return $records;
    }

    /**
     * Strip `;` comments without touching one inside quotes
     *
     * SPF/DKIM TXT values commonly contain a `;` inside them (`v=spf1 ...; -all`) —
     * cutting with `explode(';')` would silently corrupt a correct value, only to
     * surface later as mail that fails to send.
     */
    private static function stripZoneComment(string $line): string
    {
        $out = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $out .= $char . $line[$i + 1];
                $i++;
                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === ';' && !$inQuotes) {
                break;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Split into tokens, treating quoted text as a single token
     *
     * @return list<string>
     */
    private static function tokenizeZoneLine(string $line): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\S+/', $line, $matches);

        return $matches[0];
    }

    /**
     * A directive starting with `$` — only `$TTL` is accepted, everything else is rejected with a reason
     *
     * @param list<string> $tokens
     */
    private static function applyZoneDirective(array $tokens, int $defaultTtl, int $lineNo): int
    {
        $directive = strtoupper($tokens[0]);

        if ($directive === '$TTL') {
            return self::parseTtl($tokens[1] ?? '', $lineNo);
        }

        throw new ValidationError(sprintf(
            'Line %d: %s can\'t be converted back into a record in this system — %s',
            $lineNo,
            $directive,
            match ($directive) {
                '$INCLUDE' => "it tells BIND to read another file on the machine, which is outside this page's scope",
                '$ORIGIN' => 'it changes the meaning of every name that follows — write the full name with a trailing dot instead',
                '$GENERATE' => 'it produces a batch of records — write them out one at a time instead',
                default => 'only $TTL is supported',
            },
        ));
    }

    /**
     * Convert the rest of the line into a single record — returns null for a skipped SOA/NS
     *
     * @param list<string> $tokens tokens after the record's name
     * @return array{type:string,name:string,value:string,ttl:int,priority:int|null}|null
     */
    private static function zoneStatementToRecord(
        array $tokens,
        string $owner,
        string $origin,
        int $defaultTtl,
        int $lineNo,
    ): ?array {
        $ttl = $defaultTtl;
        $ttlSeen = false;

        // TTL and class can appear in either order per the standard — both must be accepted.
        while ($tokens !== []) {
            $token = $tokens[0];

            if (strtoupper($token) === 'IN') {
                array_shift($tokens);
                continue;
            }

            if (!$ttlSeen && preg_match('/^\d+[smhdwSMHDW]?$/', $token) === 1) {
                $ttl = self::parseTtl($token, $lineNo);
                $ttlSeen = true;
                array_shift($tokens);
                continue;
            }

            break;
        }

        if ($tokens === []) {
            throw new ValidationError(sprintf('Line %d: missing record type', $lineNo));
        }

        $type = strtoupper((string) array_shift($tokens));

        try {
            $name = self::relativeZoneName($owner, $origin);
        } catch (ValidationError $e) {
            throw new ValidationError(sprintf('Line %d: %s', $lineNo, $e->getMessage()));
        }

        /*
         * SOA is always skipped · **NS is skipped only at the domain apex** — there,
         * the panel always generates it from the machine's own `dns.nameservers`, so
         * keeping the user's own copy too would produce duplicate NS records.
         *
         * But **subdomain NS is entirely the user's own** — it's a delegation, handing
         * a subzone off to another DNS server to manage, a genuinely common real task ·
         * skipping it too would mean the user saves and the record silently disappears
         * while the screen reports success.
         */
        if ($type === 'SOA' || ($type === 'NS' && $name === '@')) {
            return null;
        }

        if ($tokens === []) {
            throw new ValidationError(sprintf('Line %d: %s record has no value', $lineNo, $type));
        }

        $priority = null;

        if ($type === 'MX') {
            $first = (string) array_shift($tokens);

            if (preg_match('/^\d+$/', $first) !== 1) {
                throw new ValidationError(sprintf(
                    'Line %d: an MX record needs a numeric priority before the server name, '
                        . 'e.g. `10 mail.example.com.`',
                    $lineNo,
                ));
            }

            $priority = (int) $first;
        }

        try {
            return self::validate([
                'type' => $type,
                'name' => $name,
                'value' => self::zoneRdata($type, $tokens),
                'ttl' => $ttl,
                'priority' => $priority,
            ]);
        } catch (ValidationError $e) {
            // validate()'s message doesn't know the line number — add it, otherwise
            // the user has to hunt for it themselves.
            throw new ValidationError(sprintf('Line %d: %s', $lineNo, $e->getMessage()));
        }
    }

    /**
     * Assemble a record's value from the remaining tokens
     *
     * A long TXT value (DKIM) is split into multiple quoted strings placed side by
     * side — these must always be joined back into a single value, otherwise a pasted
     * DKIM key ends up truncated in the middle with nothing to flag it.
     *
     * @param list<string> $tokens
     */
    private static function zoneRdata(string $type, array $tokens): string
    {
        if ($type === 'TXT') {
            $parts = array_map(static fn (string $token): string => self::unquoteZoneToken($token), $tokens);

            return implode('', $parts);
        }

        if (in_array($type, self::HOSTNAME_TYPES, true)) {
            // The trailing dot gets added back when the file is written — store it
            // without one to match what the form saves.
            return rtrim((string) $tokens[0], '.');
        }

        /*
         * Every other type keeps **the whole line exactly as written** — CAA has three
         * parts, SRV has four, TLSA has four, NAPTR has six · chasing after per-type
         * structure is just a closed list wearing a different shape, and it will
         * always fall behind the next type · `named-checkzone` is the arbiter of
         * whether it was written correctly.
         */
        return implode(' ', $tokens);
    }

    /** Strip quotes and escaping from a single token */
    private static function unquoteZoneToken(string $token): string
    {
        if (strlen($token) >= 2 && str_starts_with($token, '"') && str_ends_with($token, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($token, 1, -1));
        }

        return $token;
    }

    /**
     * Convert a file's name into the relative name the system stores
     *
     * **A name with no trailing dot that equals the domain name exactly must be
     * rejected**, not guessed at — BIND reads `example.com` (no dot) as
     * `example.com.example.com.`, which is almost never what the person typing it
     * meant · silently guessing `@` means the user never finds out they misunderstood,
     * and goes on to repeat the mistake somewhere nobody catches it.
     *
     * Doesn't add its own line number, since the caller already does — adding it in
     * both places produces a message that starts "Line 7: Line 7:", which reads like
     * the system is broken.
     */
    private static function relativeZoneName(string $name, string $origin): string
    {
        if ($name === '@' || $name === '*') {
            return $name;
        }

        $lower = strtolower($name);

        if (str_ends_with($lower, '.')) {
            $absolute = rtrim($lower, '.');

            if ($absolute === $origin) {
                return '@';
            }

            if (str_ends_with($absolute, '.' . $origin)) {
                return substr($absolute, 0, -strlen('.' . $origin));
            }

            throw new ValidationError(sprintf(
                'The name %s is outside the domain %s — this zone cannot declare a name outside its own domain',
                $name,
                $origin,
            ));
        }

        if ($lower === $origin) {
            throw new ValidationError(sprintf(
                'The name %s has no trailing dot, so BIND will read it as %s.%s — '
                    . 'use `@` if you mean the domain itself, or add a trailing dot: `%s.`',
                $name,
                $lower,
                $origin,
                $lower,
            ));
        }

        return $name;
    }

    /** TTL accepts both plain seconds and a trailing unit (`1h`, `30m`) as seen in copied-in files */
    private static function parseTtl(string $token, int $lineNo): int
    {
        if (preg_match('/^(\d+)([smhdwSMHDW]?)$/', $token, $m) !== 1) {
            throw new ValidationError(sprintf('Line %d: invalid TTL (%s)', $lineNo, $token));
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
            default => 1,
        };
    }

    /**
     * Assemble a zone file from all of a domain's records
     *
     * @param list<array<string,mixed>> $records
     */
    public static function toZoneFile(string $domain, array $records): string
    {
        $lines = [
            '; zone file for ' . $domain,
            '; exported from PHP Server Control Panel on ' . date('Y-m-d H:i:s'),
            '; import this at your DNS provider — the panel does not act as a DNS server',
            '',
        ];

        foreach ($records as $record) {
            $lines[] = self::recordLine($record);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A single record's line — **the one place that answers what a record looks like**
     *
     * There used to be three places writing this exact same `sprintf` — the real file
     * on disk, the file exported for the user to paste at an external DNS provider,
     * and the editable text block — and they genuinely drifted apart: the exported
     * file was the one place that never called {@see zoneValue()}, so it never wrapped
     * TXT in quotes and never added the trailing dot to CNAME/MX — the value the user
     * copied out to paste at an external provider was a different value than the one
     * this machine's own DNS actually answered with, with nothing to flag it.
     *
     * Consolidated into one place, a single change to the value-writing rule now
     * affects every place a record is shown — the same reasoning as the zone file
     * always being fully derived from the database, never patched piecemeal.
     *
     * @param array<string,mixed> $record
     */
    private static function recordLine(array $record): string
    {
        return sprintf(
            '%-20s %-6s IN %-6s %s%s',
            (string) $record['name'],
            (string) $record['ttl'],
            (string) $record['type'],
            ($record['priority'] ?? null) !== null ? $record['priority'] . ' ' : '',
            self::zoneValue((string) $record['type'], (string) $record['value']),
        );
    }

    /**
     * Records as text for the edit field — **entirely the user's own, no
     * system-generated SOA/NS**
     *
     * Differs from {@see toAuthoritativeZoneFile()} in having no header the system
     * owns · showing SOA and its serial in the edit field would invite editing
     * something that can't actually be edited, and the user would waste time tweaking
     * a serial the system overwrites every time regardless.
     *
     * Values are formatted exactly as they would be written to the real file
     * (trailing dots, TXT quoting) — **what's shown in the edit field must be what you
     * actually get**, and pasting it back unchanged must produce the exact same set
     * of records.
     *
     * @param list<array<string,mixed>> $records
     */
    public static function toEditableRecords(string $domain, array $records): string
    {
        $lines = [
            '; Records for ' . $domain . ' — fully editable, saving replaces the entire set',
            '; A line removed from this text is a genuine deletion',
            ';',
            "; The domain's own SOA and NS aren't here, since the system always generates them from the machine's own settings",
            '; A name ending in a dot is a full name · `@` means the domain itself',
            '',
        ];

        foreach ($records as $record) {
            $lines[] = self::recordLine($record);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A complete zone file BIND9 can actually load as a master — PLAN-V2 Phase E3
     *
     * Differs from `toZoneFile()` (the file exported for the user to paste at an
     * external DNS provider) in that it needs a full `SOA`/`NS` and `$TTL` — without
     * all three, `named-checkzone` rejects it immediately.
     *
     * @param list<array<string,mixed>> $records
     * @param list<string> $nameservers must have at least one — the caller verifies this first
     */
    public static function toAuthoritativeZoneFile(
        string $domain,
        array $records,
        int $serial,
        array $nameservers,
        string $soaEmail,
    ): string {
        $primaryNs = self::fqdn($nameservers[0]);

        $lines = [
            '; Managed automatically by phpcp — edits here are lost on the next sync',
            '; Edit only through the panel\'s DNS page',
            '$TTL 3600',
            sprintf('@   IN  SOA %s %s (', $primaryNs, self::soaRname($soaEmail, $domain)),
            sprintf('        %d   ; serial', $serial),
            '        3600        ; refresh (1 hour)',
            '        900         ; retry (15 minutes)',
            '        1209600     ; expire (14 days)',
            '        3600 )      ; minimum / negative-cache TTL',
        ];

        foreach ($nameservers as $ns) {
            $lines[] = sprintf('@   IN  NS  %s', self::fqdn($ns));
        }

        $lines[] = '';

        foreach ($records as $record) {
            $lines[] = self::recordLine($record);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A host value must always end with a dot in a zone file (fully qualified),
     * otherwise BIND9 silently appends the domain name itself, producing
     * `ns1.example.com.example.com` — a classic DNS bug.
     */
    private static function fqdn(string $host): string
    {
        return str_ends_with($host, '.') ? $host : $host . '.';
    }

    /**
     * Convert a value into the shape a zone file needs, based on the record's type
     *
     * · **CNAME/MX** must be an FQDN like NS, otherwise BIND9 appends the domain name
     *   itself again
     * · **TXT** must always be in quotes — a value containing whitespace (SPF, DKIM,
     *   and ACME challenge tokens) gets read by BIND9 as multiple separate strings, or
     *   rejects the whole zone, if it isn't wrapped · this used to leave it to the
     *   user to supply their own `"`, which was a trap: someone pasting an SPF value
     *   exactly as their mail provider gave it to them (with no quotes) would end up
     *   with a zone that doesn't work
     * · other types (A/AAAA/CAA) are passed through unchanged
     */
    private static function zoneValue(string $type, string $value): string
    {
        if (in_array($type, self::HOSTNAME_TYPES, true)) {
            return self::fqdn($value);
        }

        if ($type !== 'TXT') {
            // Types that store the whole line (SRV, CAA, TLSA, ...) are written out exactly as stored.
            return $value;
        }

        return self::txtCharacterStrings($value);
    }

    /**
     * Wrap a TXT value into character-strings per RFC 1035 §3.3.14 — **no more than
     * 255 bytes per chunk**
     *
     * ## A real bug this fixes (found on a real server, 2026-08-14)
     *
     * A 2048-bit DKIM key runs about 400 characters long · the system was already
     * designed to support this ({@see VALUE_MAX} = 4096, and {@see zoneRdata()} joins
     * already-split strings back into a single value so the key never comes out
     * truncated), but at **write time**, it was wrapped back out as a single quoted
     * chunk, which BIND rejects the entire file over with a message that gives no hint
     * at all what's wrong:
     *
     * ```
     * dns_rdata_fromtext: /etc/bind/zones/<domain>.zone:22: syntax error
     * ```
     *
     * The result was that the whole zone reverted — every unrelated record for that
     * domain stayed stuck on its old value, while the screen reported the save as
     * successful · splitting into chunks at write time is the correct fix, because the
     * resolver already joins every chunk back into a single value on its own, so the
     * value the destination actually receives comes out exactly the same regardless of
     * how many chunks it was split into.
     *
     * Split from **the raw bytes before escaping**, not after — `\"` is two bytes in
     * the file but counts as a single byte on the wire, so splitting after escaping
     * would both produce chunks that are longer than intended and risk splitting right
     * through an escape sequence, closing a quote in the wrong place.
     */
    private static function txtCharacterStrings(string $value): string
    {
        $raw = self::txtRawBytes($value);

        if ($raw === '') {
            return '""';
        }

        $quoted = array_map(
            // A quote appearing in the middle of a value must be escaped, otherwise it closes the string early
            static fn (string $chunk): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $chunk) . '"',
            str_split($raw, self::TXT_STRING_MAX),
        );

        return implode(' ', $quoted);
    }

    /**
     * A TXT value as the actual bytes on the wire — strips quotes if the stored value already has them
     *
     * A value stored in the database can arrive in two shapes: with no quotes at all
     * (the add-record form, and {@see zoneRdata()} which already stripped them), or
     * with quotes already attached (a user pasting a value exactly as their mail
     * provider handed it to them) · both must resolve to the same set of bytes before
     * splitting, otherwise a value that already has quotes attached would have its
     * length overcounted and get split in the wrong place.
     *
     * Every single token must be quoted for this to count as "already wrapped" — a
     * value like `"quoted" not wrapped` means the quote is part of the text itself,
     * and must be escaped, not stripped.
     */
    private static function txtRawBytes(string $value): string
    {
        if (!str_starts_with($value, '"')) {
            return $value;
        }

        $tokens = self::tokenizeZoneLine($value);

        foreach ($tokens as $token) {
            if (strlen($token) < 2 || !str_starts_with($token, '"') || !str_ends_with($token, '"')) {
                return $value;
            }
        }

        return implode('', array_map(static fn (string $t): string => self::unquoteZoneToken($t), $tokens));
    }

    /**
     * Convert an admin email into the SOA's RNAME format (a dot in place of @, per RFC 1035 §3.3.13)
     *
     * A dot already present in the local part (e.g. "first.last@example.com") must be
     * escaped with \. before replacing @, otherwise BIND9 misreads it as part of the
     * domain rather than part of the username.
     */
    private static function soaRname(string $soaEmail, string $domain): string
    {
        if ($soaEmail === '') {
            return self::fqdn('hostmaster.' . $domain);
        }

        if (!str_contains($soaEmail, '@')) {
            // Already in RNAME shape (dot in place of @ from the start) — pass through unchanged.
            return self::fqdn($soaEmail);
        }

        [$local, $host] = explode('@', $soaEmail, 2);

        return self::fqdn(str_replace('.', '\\.', $local) . '.' . $host);
    }
}
