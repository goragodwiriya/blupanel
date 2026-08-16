<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * Outbound mail through Postfix — deliberately just "sending", never a full mail server
 *
 * ## Why this doesn't build a full mail server
 *
 * What a website genuinely needs is "can send a signup confirmation email",
 * an entirely different matter from being a mail server that receives
 * inbound mail and stores mailboxes · the latter needs Dovecot + a mail
 * user system + quotas + antispam + antivirus + webmail + mailbox backups,
 * and most importantly, **someone has to watch the IP's reputation constantly**.
 *
 * An incompletely configured mail server is more burden than benefit: if
 * SPF/DKIM/DMARC/rDNS aren't all in place, mail lands in spam or gets
 * rejected outright, and if a relay is misconfigured into an open relay,
 * the machine gets used to send spam within hours and the IP ends up
 * permanently blacklisted — affecting every site on that same machine.
 *
 * So v1 supports two modes that are both safe and genuinely usable:
 *
 *   local  sends directly from this machine — the simplest, suited to
 *          internal mail and notifications, but the destination may treat
 *          it as spam if DNS isn't fully set up
 *   relay  sends through an external provider (SendGrid, Amazon SES,
 *          Gmail, etc.) — **recommended for a public-facing site**, since
 *          IP reputation becomes their burden instead
 *
 * Both modes configure Postfix to **only ever accept a send request from
 * this machine itself** (loopback), so it can never become an open relay.
 */
final class MailManager
{
    private const MAIN_CF = '/etc/postfix/main.cf';
    private const MASTER_CF = '/etc/postfix/master.cf';

    /** The port other mail servers use to send mail to us */
    private const SMTP_PORT = 25;
    private const SASL_FILE = '/etc/postfix/sasl_passwd';
    private const POSTFIX = '/usr/sbin/postfix';
    private const POSTMAP = '/usr/sbin/postmap';

    public function __construct(private readonly Template $templates)
    {
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path(self::MAIN_CF));
    }

    /**
     * The current status, read from the real machine
     *
     * @return array{installed:bool,mode:string,relay_host:string,hostname:string,queued:int,open_relay:bool}
     */
    public function status(Executor $executor): array
    {
        if (!$this->isInstalled($executor)) {
            return [
                'installed' => false, 'mode' => '', 'relay_host' => '',
                'hostname' => '', 'queued' => 0, 'open_relay' => false,
            ];
        }

        $main = '';

        try {
            $main = $executor->readFile($executor->path(self::MAIN_CF));
        } catch (\Throwable) {
            // Even unreadable, the rest of the status can still be reported — better than breaking the whole page
        }

        $relay = $this->directive($main, 'relayhost');

        return [
            'installed' => true,
            'mode' => $relay === '' ? 'local' : 'relay',
            'relay_host' => trim($relay, '[]'),
            'hostname' => $this->directive($main, 'myhostname'),
            'queued' => $this->queueSize($executor),
            // Checks whether it's accidentally open to the entire world — the single most expensive mistake possible
            'open_relay' => $this->looksLikeOpenRelay($main),
        ];
    }

    private function directive(string $main, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . '\s*=\s*(.+)$/m', $main, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }

    /**
     * Guesses whether this is an open relay, from the two most dangerous values
     *
     * Doesn't cover every case, but catches the most common mistake: inet_interfaces set to all with mynetworks allowed too broadly.
     */
    private function looksLikeOpenRelay(string $main): bool
    {
        $interfaces = $this->directive($main, 'inet_interfaces');
        $networks = $this->directive($main, 'mynetworks');

        if ($interfaces === '' || str_contains($interfaces, 'loopback-only')) {
            return false;
        }

        return str_contains($networks, '0.0.0.0/0') || str_contains($networks, '::/0');
    }

    private function queueSize(Executor $executor): int
    {
        $result = $executor->exec([$executor->path('/usr/bin/mailq')], timeout: 15);

        if (!$result->ok()) {
            return 0;
        }

        // mailq either prints "Mail queue is empty" or ends with a summary "-- N Kbytes in M Requests."
        if (preg_match('/(\d+) Request/', $result->stdout, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Rewrites Postfix's entire configuration
     *
     * @param array{mode:string,hostname:string,from:string,relay_host:string,relay_port:int,relay_user:string,relay_password:string,relay_tls:bool} $config
     */
    public function apply(Executor $executor, array $config, bool $reload = true): array
    {
        if (!$this->isInstalled($executor)) {
            throw new ValidationError(
                'Postfix was not found on this machine — install it with apt install postfix first '
                . '(choosing "Internet Site" or "Satellite system" during install is fine — the system will rewrite the config entirely)',
            );
        }

        $mode = $config['mode'];

        if (!in_array($mode, ['local', 'relay'], true)) {
            throw new ValidationError('Mail mode must be local or relay');
        }

        $relayLine = '';
        $transaction = new ConfigTransaction($executor);

        if ($mode === 'relay') {
            $host = self::assertHost($config['relay_host']);
            $port = self::assertPort($config['relay_port']);

            // Square brackets tell Postfix not to look up this host's own MX
            // record — nearly every relay provider requires this shape, or mail fails to send at all
            $relayLine = sprintf('[%s]:%d', $host, $port);

            if ($config['relay_user'] !== '') {
                $transaction->write(
                    self::SASL_FILE,
                    sprintf(
                        "%s %s:%s\n",
                        $relayLine,
                        Template::assertValue('relay_user', $config['relay_user']),
                        Template::assertValue('relay_password', $config['relay_password']),
                    ),
                    // The mail provider's own password — no other user on this machine may ever read it
                    0600,
                );
            }
        }

        $hosting = (bool) ($config['hosting'] ?? false);
        $interfaces = $hosting ? 'all' : 'loopback-only';

        /*
         * **Changing which network interface is listened on needs a restart, never a reload**
         *
         * `postfix reload` re-reads every value except the one that
         * requires opening a fresh socket · turning mail on for the first
         * domain and only calling reload produces a main.cf that says
         * `all`, while Postfix keeps listening on loopback only — mail from
         * the internet can never reach this machine, and nothing complains,
         * since everything in the file is correct (found on the real
         * production machine, 2026-08-12).
         *
         * The original value is read from the file before it's overwritten, to know whether this run genuinely changes it.
         */
        $restartRequired = $this->needsRestart($executor, $hosting);

        $transaction->write(self::MAIN_CF, $this->templates->render('postfix/main.cf.tpl', [
            'HOSTNAME' => self::assertHost($config['hostname']),
            'ORIGIN' => self::assertHost($config['hostname']),
            'RELAY_HOST' => $relayLine,
            'SASL_ENABLED' => $mode === 'relay' && $config['relay_user'] !== '' ? 'yes' : 'no',
            'TLS_SECURITY' => $config['relay_tls'] ? 'encrypt' : 'may',
            // Accepting inbound mail requires listening on every network
            // interface, not just loopback — but mynetworks stays exactly
            // as narrow, so an outsider sending through us still always has to log in first
            'INET_INTERFACES' => $interfaces,
            /*
             * The machine's own name must be in mydestination, or mail the
             * system generates itself (cron output, system messages) sits
             * in the queue forever with "loops back to myself".
             *
             * The one exception: that same name is also used as a mailbox
             * domain — having it in both places at once makes Postfix
             * complain and refuse mail for that domain entirely (see hosting.cf.tpl).
             */
            'MYDESTINATION' => ($config['virtual_hostname'] ?? false)
                ? 'localhost'
                : 'localhost, $myhostname',
            'HOSTING_SECTION' => new SafeBlock($hosting ? $this->hostingSection($config) : ''),
            /*
             * **Postfix has no include directive for main.cf** — unlike
             * Apache/nginx/Dovecot, which can point at an admin's own file
             * · so its content is appended here at write time instead, and
             * because Postfix uses whichever value is declared last, the
             * admin's own value at the very end always wins.
             *
             * The original stays a separate file the panel never touches, so nothing written there is lost when main.cf is rewritten.
             */
            'CUSTOM_SECTION' => new SafeBlock(
                (new CustomConfig())->read($executor, 'postfix'),
            ),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->write(self::MASTER_CF, $this->templates->render('postfix/master.cf.tpl', [
            'SUBMISSION_SECTION' => new SafeBlock(
                $hosting ? $this->templates->render('postfix/submission.cf.tpl', []) : '',
            ),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->commit(fn (): array => $this->testConfig($executor));

        if ($mode === 'relay' && $config['relay_user'] !== '') {
            // postmap creates the .db file Postfix actually reads — the plain text file alone has no effect
            $result = $executor->exec([$executor->path(self::POSTMAP), $executor->path(self::SASL_FILE)], timeout: 30);

            if (!$result->ok()) {
                throw new ExecutionFailed('Failed to build Postfix\'s password table: ' . trim($result->stderr));
            }

            $executor->changeMode($executor->path(self::SASL_FILE . '.db'), 0600);
        }

        // A caller running a larger job (turning on mail for a domain,
        // which still has to write lookup tables afterward) triggers
        // reload itself, once, at the end — reloading partway through and
        // failing would leave the remaining steps never run, even though every file already written is correct
        if ($reload) {
            $this->reload($executor);
        }

        return ['mode' => $mode, 'relay' => $relayLine, 'restart_required' => $restartRequired];
    }

    /**
     * The part of main.cf that only exists when inbound mail is turned on
     *
     * Kept as its own template because it's a separate matter from
     * sending, and most machines will never have this section at all — a
     * shorter config file is a config file that's easier to check.
     *
     * @param array<string,mixed> $config
     */
    private function hostingSection(array $config): string
    {
        // If the mail hostname has no certificate yet, falls back to the
        // distro's own — better than no TLS at all, and the mail readiness page will point out no real one has been requested yet
        $tls = MailCertificate::pathsOrDefault(
            (string) ($config['tls_cert'] ?? ''),
            (string) ($config['tls_key'] ?? ''),
        );

        return $this->templates->render('postfix/hosting.cf.tpl', [
            'TLS_CERT' => $tls['cert'],
            'TLS_KEY' => $tls['key'],
        ]);
    }

    /**
     * Validates the config with Postfix itself
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $result = $executor->exec([$executor->path(self::POSTFIX), 'check'], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /**
     * Does this need a restart? — **compared against what the daemon is genuinely doing, never against the previous file**
     *
     * Comparing "the old value in the file" against "the new value about to
     * be written" sounds reasonable, but misses the single most important
     * case: a machine whose file already said `all` from an earlier run,
     * but Postfix was never actually restarted, so it's still only
     * listening on loopback · the next run would see "nothing changed" and
     * never restart it either — stuck that way forever with nothing ever
     * complaining (genuinely found on a real machine).
     *
     * Checked against the port genuinely open instead · can't check = restart just to be safe, more costly but never loses mail.
     */
    private function needsRestart(Executor $executor, bool $hosting): bool
    {
        $result = $executor->exec([$executor->path('/usr/bin/ss'), '-ltnH'], timeout: 10);

        if (!$result->ok()) {
            return true;
        }

        $public = false;

        foreach (explode("\n", $result->stdout) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $local = $fields[3] ?? '';

            if (!str_ends_with($local, ':' . self::SMTP_PORT)) {
                continue;
            }

            $address = trim(substr($local, 0, -strlen(':' . self::SMTP_PORT)), '[]');

            if ($address !== '127.0.0.1' && $address !== '::1') {
                $public = true;
                break;
            }
        }

        return $public !== $hosting;
    }

    /**
     * Fully restarts Postfix — used only when the listening port changes
     *
     * More costly than a reload (there's a short window where mail isn't accepted), so this is never the default.
     */
    public function restart(Executor $executor): void
    {
        $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'restart', 'postfix'],
            timeout: 60,
        );
    }

    public function reload(Executor $executor): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'postfix'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                "The configuration was written successfully, but reloading postfix failed — the new configuration will have no effect\n\n"
                . trim($result->stderr ?: $result->stdout),
            );
        }
    }

    /**
     * Sends a test email
     *
     * Uses `sendmail`, not PHP's own mail() function, because what needs
     * proving is that "the system's own path" genuinely works — the exact
     * same path a user's website will use.
     */
    public function sendTest(Executor $executor, string $to, string $from): array
    {
        $to = self::assertEmail($to);
        $from = self::assertEmail($from);

        $message = sprintf(
            "From: %s\r\nTo: %s\r\nSubject: =?UTF-8?B?%s?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n",
            $from,
            $to,
            base64_encode('Outbound mail test from PHP Server Control Panel'),
            "If you received this message, the server's outbound mail is working\n\n"
            . 'Sent at ' . date('d/m/Y H:i:s'),
        );

        $result = $executor->exec(
            [$executor->path('/usr/sbin/sendmail'), '-t', '-i', '-f', $from],
            timeout: 30,
            stdin: $message,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to send the test email: ' . trim($result->stderr ?: $result->stdout));
        }

        return [
            'to' => $to,
            'queued' => $this->queueSize($executor),
        ];
    }

    public static function assertEmail(string $email): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError("Invalid email: {$email}");
        }

        return $email;
    }

    public static function assertHost(string $host): string
    {
        if ($host === '') {
            throw new ValidationError('A hostname must be specified');
        }

        // Accepts either a domain name or an IP — some relay providers give out an IP
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) !== 1) {
            throw new ValidationError("Invalid hostname: {$host}");
        }

        return strtolower($host);
    }

    public static function assertPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new ValidationError('Port number must be between 1 and 65535');
        }

        return $port;
    }
}
