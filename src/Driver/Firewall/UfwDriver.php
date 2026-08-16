<?php

declare (strict_types = 1);

namespace Phpcp\Driver\Firewall;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Manages the firewall through ufw — PROMPT.md says "don't make this more complicated than it needs to be"
 *
 * ufw was chosen because it's the standard tool on Debian/Ubuntu with a
 * genuinely straightforward command shape — an admin already familiar with
 * ufw understands what the panel is doing immediately, with nothing new to learn.
 *
 * Security: every argument is a number or an IP that's already been
 * validated, and the rule allowing the panel's own port is pinned and cannot be deleted (ARCHITECTURE §5.4).
 */
final class UfwDriver
{
    private const BINARY = '/usr/sbin/ufw';

    /**
     * The supported protocols — ufw supports more than this, but only these are genuinely used
     *
     * 'any' exists to read and reverse an existing rule an admin created by
     * hand with no protocol specified — the web page's own add-rule form
     * only ever offers a choice of tcp or udp.
     */
    private const PROTOCOLS = ['tcp', 'udp', 'any'];

    /**
     * Deliberately has no reject and no limit — reject differs from deny
     * only in what it replies with, which isn't something that should be
     * offered on a web page without an explanation · limit is mainly for
     * SSH and deserves a screen of its own, rather than being mixed into a general add-rule form.
     */
    private const ACTIONS = ['allow', 'deny'];

    public function isInstalled(Executor $executor): bool
    {
        // In simulated mode, every ufw command is intercepted, so this is always considered available
        // regardless of whether the machine running the test genuinely has ufw installed
        return $executor->isSimulated() || $executor->exists(self::BINARY);
    }

    /**
     * The full status and every rule
     *
     * `readable` is deliberately kept separate from `active`, because "off"
     * and "can't be read" are two entirely unrelated situations for someone
     * making a decision · collapsing them into one value would make the
     * screen announce the machine has no protection at all when the truth
     * is the system simply doesn't know — an admin might then turn the
     * firewall on again unnecessarily, or tear down and rebuild the whole rule set for nothing.
     *
     * @return array{installed:bool,active:bool,readable:bool,rules:list<array<string,mixed>>,raw:string,note:string}
     */
    public function status(Executor $executor): array
    {
        $blank = ['installed' => false, 'active' => false, 'readable' => true, 'rules' => [], 'raw' => '', 'note' => ''];

        if (!$this->isInstalled($executor)) {
            return $blank;
        }

        $result = $executor->exec([self::BINARY, 'status', 'numbered'], timeout: 20);

        if (!$result->ok()) {
            $error = trim($result->stderr ?: $result->stdout);

            // ufw reads the genuinely-running status from iptables, which
            // requires CAP_NET_ADMIN — in a container without
            // --cap-add=NET_ADMIN, this step always fails · but `ufw show
            // added` reads straight from the config file, so it still
            // works — showing the configured rules anyway is better than
            // an empty table, which would wrongly imply no rules exist at all
            if (str_contains($error, 'Permission denied') || str_contains($error, 'problem running iptables')) {
                return [
                    'installed' => true,
                    'active' => false,
                    'readable' => false,
                    'rules' => $this->added($executor),
                    'raw' => $error,
                    'note' => "Could not read the firewall's genuine running status, because the kernel's "
                        . 'iptables could not be reached (a container must run with --cap-add=NET_ADMIN) — '
                        . 'the rules below are the configured rules, but the system cannot confirm whether they are currently being enforced',
                ];
            }

            throw new ExecutionFailed('Failed to read firewall status: ' . $error);
        }

        $raw = $result->stdout;

        if (str_contains($raw, 'Status: active')) {
            return [
                'installed' => true,
                'active' => true,
                'readable' => true,
                'rules' => $this->parseRules($raw),
                'raw' => $raw,
                'note' => '',
            ];
        }

        // A disabled ufw prints only "Status: inactive" with no rules shown
        // at all, even though every rule is still fully stored · trusting
        // that output directly would make the screen say "no rules" at
        // exactly the moment an admin most needs to check them — right
        // before turning it on — so `ufw show added` is read instead
        return [
            'installed' => true,
            'active' => false,
            'readable' => true,
            'rules' => $this->added($executor),
            'raw' => $raw,
            'note' => '',
        ];
    }

    /**
     * The configured rules, read straight from ufw's own config files — never touches the kernel
     *
     * @return list<array<string,mixed>>
     */
    private function added(Executor $executor): array
    {
        $result = $executor->exec([self::BINARY, 'show', 'added'], timeout: 20);

        return $result->ok() ? $this->parseAdded($result->stdout) : [];
    }

    /**
     * Parses `ufw show added`'s output — ufw returns rules as the command lines that created them
     *
     *   Added user rules (see 'ufw status' for running firewall):
     *   ufw allow 22/tcp
     *   ufw allow from 10.0.0.0/8 to any port 3306 proto tcp
     *
     * Carries no numbering the way `status numbered` does, so numbers are
     * assigned in the order they appear — safe to use for display on screen, since an actual delete refers to the rule's own content, not a number.
     *
     * @return list<array<string,mixed>>
     */
    private function parseAdded(string $raw): array
    {
        $rules = [];
        $number = 0;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) < 3 || $parts[0] !== 'ufw') {
                continue;
            }

            $action = strtolower($parts[1]);

            if (!in_array($action, ['allow', 'deny', 'reject', 'limit'], true)) {
                continue;
            }

            $rule = ['port' => '', 'protocol' => '', 'source' => ''];
            $spec = array_slice($parts, 2);

            // The comment section is stripped before parsing, and its text is kept for display on screen
            $comment = '';
            $at = array_search('comment', $spec, true);

            if ($at !== false) {
                $comment = trim(implode(' ', array_slice($spec, $at + 1)), " '\"");
                $spec = array_slice($spec, 0, $at);
            }

            if ($spec === []) {
                continue;
            }

            if (preg_match('#^(\d+(?::\d+)?)(?:/(tcp|udp))?$#', $spec[0], $m) === 1) {
                $rule['port'] = $m[1];
                $rule['protocol'] = $m[2] ?? 'any';
            } else {
                $count = count($spec);

                for ($i = 0; $i < $count; $i++) {
                    $next = $spec[$i + 1] ?? '';

                    match ($spec[$i]) {
                        'from' => $rule['source'] = $next === 'any' ? '' : $next,
                        'port' => $rule['port'] = $next,
                        'proto' => $rule['protocol'] = $next,
                        default => null,
                    };
                }

                if ($rule['protocol'] === '') {
                    $rule['protocol'] = 'any';
                }
            }

            $target = $rule['protocol'] === 'any' ? $rule['port'] : $rule['port'].'/'.$rule['protocol'];

            $rules[] = [
                'number' => ++$number,
                'target' => $target,
                'port' => $rule['port'],
                'protocol' => $rule['protocol'],
                'action' => strtoupper($action),
                'direction' => 'IN',
                'source' => $rule['source'] === '' ? 'Anywhere' : $rule['source'],
                'source_spec' => $rule['source'],
                'manageable' => $rule['port'] !== '',
                'comment' => $comment,
                'is_panel_port' => false
            ];
        }

        return $rules;
    }

    /**
     * Parses `ufw status numbered`'s output into structured data
     *
     * The shape ufw returns:
     *   [ 1] 22/tcp                     ALLOW IN    Anywhere
     *   [ 2] 8443/tcp                   ALLOW IN    203.0.113.0/24
     *
     * @return list<array<string,mixed>>
     */
    private function parseRules(string $raw): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\[\s*(\d+)\]\s+(\S+)\s+(ALLOW|DENY|REJECT|LIMIT)\s+(IN|OUT)\s+(.+?)\s*$/', $line, $m) !== 1) {
                continue;
            }

            [, $number, $target, $action, $direction, $source] = $m;

            // Skips ufw's duplicate IPv6 entry — the same rule as the IPv4 one already shown
            if (str_contains($target, '(v6)') || str_contains($source, '(v6)')) {
                continue;
            }

            $port = '';
            $protocol = '';

            if (preg_match('#^(\d+(?::\d+)?)/(tcp|udp)$#', $target, $p) === 1) {
                $port = $p[1];
                $protocol = $p[2];
            } elseif (preg_match('/^\d+(:\d+)?$/', $target) === 1) {
                $port = $target;
                $protocol = 'any';
            }

            $from = trim($source);

            $rules[] = [
                'number' => (int) $number,
                'target' => $target,
                'port' => $port,
                'protocol' => $protocol,
                'action' => $action,
                'direction' => $direction,
                'source' => $from,
                // The shape that can be fed back into ufw — ufw's own 'Anywhere' means no source specified
                'source_spec' => strcasecmp($from, 'Anywhere') === 0 ? '' : $from,
                // A rule that can't be translated back into a command
                // (e.g. one referencing a service name or interface) must
                // never be deletable from the web page, since it can't be reversed if the user changes their mind
                'manageable' => $port !== '' && $direction === 'IN',
                // `ufw status` never shows a rule's comment — it only ever appears in `ufw show added`
                'comment' => '',
                // The caller sets this value, since it's the one that knows the panel's own port
                'is_panel_port' => false
            ];
        }

        return $rules;
    }

    /**
     * @param string $port
     * @return mixed
     */
    public static function assertPort(string $port): string
    {
        // Supports both a single port and a range, e.g. 6000:6010, matching what ufw itself supports
        if (preg_match('/^(\d{1,5})(:(\d{1,5}))?$/', $port, $m) !== 1) {
            throw new ValidationError('Invalid port format (use 8080 or 6000:6010)');
        }

        // Compared against '' directly, not with array_filter — '0' is falsy in PHP,
        // and filtering with array_filter would let port 0 slip past the range check entirely
        foreach ([$m[1], $m[3] ?? ''] as $value) {
            if ($value === '') {
                continue;
            }

            if ((int) $value < 1 || (int) $value > 65535) {
                throw new ValidationError('Port number must be between 1 and 65535');
            }
        }

        if (isset($m[3]) && $m[3] !== '' && (int) $m[3] <= (int) $m[1]) {
            throw new ValidationError('A port range must go from low to high');
        }

        return $port;
    }

    /**
     * @param string $protocol
     * @return mixed
     */
    public static function assertProtocol(string $protocol): string
    {
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            throw new ValidationError('Protocol must be tcp or udp');
        }

        return $protocol;
    }

    /** The connection's source — empty means everywhere */
    public static function assertSource(string $source): string
    {
        if ($source === '' || strtolower($source) === 'any') {
            return '';
        }

        // Supports both a single IP and CIDR
        [$address, $bits] = array_pad(explode('/', $source, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('The source address must be a valid IP or CIDR');
        }

        if ($bits !== null) {
            $max = str_contains($address, ':') ? 128 : 32;

            if (preg_match('/^\d{1,3}$/', $bits) !== 1 || (int) $bits < 0 || (int) $bits > $max) {
                throw new ValidationError("The prefix length must be between 0 and {$max}");
            }
        }

        return $source;
    }

    /**
     * @param string $action
     * @return mixed
     */
    public static function assertAction(string $action): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new ValidationError('Action must be allow or deny');
        }

        return $action;
    }

    /**
     * Adds a rule — argv is assembled entirely from values that have already passed a validator
     */
    public function rule(
        Executor $executor,
        string $action,
        string $port,
        string $protocol,
        string $source,
        string $comment = '',
    ): void {
        $argv = array_merge([self::BINARY, self::assertAction($action)], $this->spec($port, $protocol, $source));

        if ($comment !== '') {
            array_push($argv, 'comment', self::assertComment($comment));
        }

        $result = $executor->exec($argv, timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'Could not add a firewall rule inside this container '.
                    '(ufw needs kernel network permission / CAP_NET_ADMIN, or must run on a real server/VM)'
                );
            }

            throw new ExecutionFailed('Failed to add firewall rule: '.$err);
        }
    }

    /**
     * Deletes a rule by its own content, never by number
     *
     * ufw's own rule numbers shift every time something is deleted — if a
     * rollback referred to a number remembered earlier, it could end up
     * deleting an unrelated rule that shifted into that slot instead ·
     * referring to a rule by its content is therefore safer, and is
     * already the way ufw itself supports doing it.
     */
    public function removeRule(
        Executor $executor,
        string $action,
        string $port,
        string $protocol,
        string $source,
    ): void {
        $argv = array_merge(
            [self::BINARY, '--force', 'delete', self::assertAction($action)],
            $this->spec($port, $protocol, $source),
        );

        $result = $executor->exec($argv, timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'Could not delete a firewall rule inside this container '.
                    '(ufw needs kernel network permission / CAP_NET_ADMIN, or must run on a real server/VM)'
                );
            }

            throw new ExecutionFailed('Failed to delete firewall rule: '.$err);
        }
    }

    /**
     * The part of argv that states what this rule covers — shared between
     * adding and deleting, so a delete command is guaranteed to refer to
     * exactly the same rule that was added.
     *
     * @return list<string>
     */
    private function spec(string $port, string $protocol, string $source): array
    {
        self::assertPort($port);
        self::assertProtocol($protocol);
        self::assertSource($source);

        if ($source !== '') {
            // ufw's own full shape: ufw allow from <src> to any port <port> proto <proto>
            $spec = ['from', $source, 'to', 'any', 'port', $port];

            return $protocol === 'any' ? $spec : array_merge($spec, ['proto', $protocol]);
        }

        return [$protocol === 'any' ? $port : $port.'/'.$protocol];
    }

    /**
     * @param Executor $executor
     */
    public function enable(Executor $executor): void
    {
        // --force skips ufw's own interactive "this might cut your SSH connection" prompt —
        // that risk is instead managed by RollbackGuard at a higher layer
        $result = $executor->exec([self::BINARY, '--force', 'enable'], timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'Could not turn the firewall on inside this container '.
                    '(ufw needs kernel network permission / CAP_NET_ADMIN, or must run on a real server/VM)'
                );
            }

            throw new ExecutionFailed('Failed to turn on firewall: '.$err);
        }
    }

    /**
     * @param Executor $executor
     */
    public function disable(Executor $executor): void
    {
        $result = $executor->exec([self::BINARY, 'disable'], timeout: 30);

        if (!$result->ok()) {
            $err = trim($result->stderr ?: $result->stdout);
            if (
                str_contains($err, 'Permission denied') ||
                str_contains($err, 'problem running') ||
                str_contains($err, 'iptables-restore')
            ) {
                throw new ExecutionFailed(
                    'Could not change the firewall state inside this container '.
                    '(ufw needs kernel network permission / CAP_NET_ADMIN, or must run on a real server/VM)'
                );
            }

            throw new ExecutionFailed('Failed to turn off firewall: '.$err);
        }
    }

    /**
     * @param string $comment
     * @return mixed
     */
    private static function assertComment(string $comment): string
    {
        $clean = trim(preg_replace('/[^\p{Thai}\p{L}\p{N}\s._-]/u', '', $comment) ?? '');

        if (mb_strlen($clean) > 64) {
            $clean = mb_substr($clean, 0, 64);
        }

        return $clean;
    }
}
