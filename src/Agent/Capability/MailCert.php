<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Mail\MailCertificate;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * Binds the mail hostname's certificate to Postfix and Dovecot — PLAN-MAIL phase M3
 *
 * Does two things that need doing but nobody was doing yet:
 *
 *   1. **Finds a certificate that covers the mail hostname** already on the
 *      machine, and points both daemons at it instead of the distro's
 *      snakeoil certificate, which every mail client warns about.
 *   2. **Tells the daemons when the certificate has been renewed** ·
 *      certbot renews on its own every 60 days without ever going through
 *      the panel, and Dovecot holds onto whatever certificate it read at
 *      start time until told to reload — without this job, a customer
 *      would run into an expired certificate opening their mailbox, even
 *      though the file on disk is already a fresh one and nothing on the
 *      screen looks wrong at all.
 *
 * **Never requests a certificate on its own** — the reason lives at the top
 * of the `MailCertificate` class · an admin adds the mail hostname as a
 * website domain, then clicks the existing "request certificate" button on
 * the SSL page as normal.
 *
 * **Never throws when no certificate exists yet**, because this job also
 * runs on a daily schedule · a machine that hasn't requested a certificate
 * yet isn't a broken machine, it just hasn't gotten there yet — a job that
 * fails every day is a job an admin stops reading within a week, and the day
 * it genuinely fails goes unnoticed by anyone.
 */
final class MailCert extends MailCapability
{
    public static function name(): string
    {
        return 'mail.cert';
    }

    /**
     * The mail hostname's certificate belongs to the whole machine, not any
     * one domain — a site owner who can manage their own mailbox shouldn't
     * be able to change a certificate every domain on the machine shares.
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return "Bind the mail hostname's certificate to Postfix and Dovecot";
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        // The same name Postfix actually announces, not just the form field's value — see mailHostname()
        $hostname = self::mailHostname($settings);
        $domains = (new MailboxRepository($context->db))->enabledDomains();

        /*
         * No domain has mail enabled = there's no receiving section in
         * main.cf at all, so there's nowhere to put the certificate ·
         * rewriting config files here would do nothing but disturb a
         * machine that only uses Postfix to send notification mail.
         */
        if ($domains === []) {
            return $this->idle('No domain has mail enabled yet — a mail certificate has no effect on anything yet');
        }

        if ($hostname === '') {
            return $this->idle('The mail hostname is not set yet — set it on the settings page first, then bind a certificate');
        }

        $certificates = new MailCertificate(new CertbotManager());
        $found = $certificates->locate($executor, $hostname);

        // No real certificate yet = fall back to the distro's own · **still
        // has to be written to the config files regardless** — see the
        // reasoning in drift() — a line that never existed is a line that
        // can't be edited on the day a certificate finally shows up
        $desired = MailCertificate::pathsOrDefault(
            (string) ($found['cert'] ?? ''),
            (string) ($found['key'] ?? ''),
        );

        $moved = $settings->get('mail.tls_cert') !== ($found['cert'] ?? '');

        /*
         * After a renewal the path is identical down to the character —
         * comparing paths alone isn't enough, and this is the most common
         * case of all (every 60 days, for the machine's entire lifetime).
         */
        $renewed = $found !== null
            && $certificates->changedSince($executor, $found['cert'], MailboxManager::DOVECOT_CONF);

        $drifted = $this->drift($executor, $desired['cert'])
            || $this->outdated($executor, $context);

        if (!$moved && !$renewed && !$drifted) {
            return $this->idle(
                $found === null
                    ? sprintf(
                        'No certificate covers %s on this machine yet — add %s as a website domain, '
                        . 'then click "request certificate" on the SSL page as normal, and mail will '
                        . 'follow along and use it automatically · a name with no public DNS yet '
                        . '(e.g. ending in .test or .local) can never request a real certificate at '
                        . 'all — choose "self-signed" instead — mail can use that kind too, and at '
                        . 'least the name in it matches what the machine announces '
                        . "(in the meantime it's using the distro's own certificate, which mail clients will warn about)",
                        $hostname,
                        $hostname,
                    )
                    : sprintf('The certificate for %s is already up to date (%d day(s) left)', $hostname, $found['days_left']),
                $found,
            );
        }

        $settings->save([
            'mail.tls_cert' => (string) ($found['cert'] ?? ''),
            'mail.tls_key' => (string) ($found['key'] ?? ''),
        ]);

        // Rewrites main.cf and 99-phpcp.conf entirely, then reloads both daemons
        $this->sync($executor, $context);

        return [
            'found' => $found !== null,
            'changed' => true,
            'hostname' => $hostname,
            'certificate' => $found,
            'message' => match (true) {
                $found === null => sprintf(
                    'No certificate covers %s yet — set Dovecot and Postfix to share the same distro '
                    . 'certificate for now, request a real one on the SSL page whenever ready',
                    $hostname,
                ),
                $moved => sprintf(
                    "Mail is now using %s's certificate (%s · %d day(s) left)",
                    $hostname,
                    $found['source'] === 'letsencrypt' ? "Let's Encrypt" : 'self-signed',
                    $found['days_left'],
                ),
                default => sprintf(
                    "%s's certificate changed — told Postfix and Dovecot to read the new one (%d day(s) left)",
                    $hostname,
                    $found['days_left'],
                ),
            },
        ];
    }

    /**
     * Does the config file genuinely in use mention the certificate we want?
     *
     * **Necessary because these files are only ever rewritten when someone
     * touches a mailbox** · a panel upgrade that adds a new line to a
     * template has no effect at all on a machine whose mail was already set
     * up, until someone creates or deletes a mailbox — which might not
     * happen again for years.
     *
     * Genuinely found on a real machine: `doveconf -n` reported `ssl_cert`
     * as the distro's own certificate, even though the template already set
     * it correctly, because the on-disk `99-phpcp.conf` was still the file
     * generated before that template change · the result was a feature that
     * was "already done" never actually reaching a single machine in production.
     */
    /**
     * Is the on-disk config file older than the template used to generate it?
     *
     * **A problem that bit this phase three separate times:** installing a
     * new panel version doesn't mean files under `/etc` get rewritten ·
     * those files are only written when someone triggers a mail operation,
     * so a machine whose mail was already set up keeps holding files from
     * before the upgrade indefinitely — a template fix never reaches a
     * single real machine, and nothing complains, because mail keeps
     * working completely normally the whole time.
     *
     * Compares the template's modification time against the file it
     * generated — answers "upgraded, but not yet rewritten" directly,
     * without needing to know what actually changed in the template · the
     * same technique `webserver.rescan` uses for vhosts.
     */
    private function outdated(Executor $executor, Context $context): bool
    {
        $templates = rtrim($context->config->paths->templates(), '/');

        // Template → the file it generates · main.cf is assembled from two templates, so both are compared
        $pairs = [
            $templates . '/postfix/main.cf.tpl' => '/etc/postfix/main.cf',
            $templates . '/postfix/hosting.cf.tpl' => '/etc/postfix/main.cf',
            $templates . '/postfix/master.cf.tpl' => '/etc/postfix/master.cf',
            $templates . '/dovecot/99-phpcp.conf.tpl' => MailboxManager::DOVECOT_CONF,
        ];

        foreach ($pairs as $template => $generated) {
            /*
             * **The template is read straight from disk, never through the
             * Executor** — it's a file belonging to the panel itself,
             * shipped with the code, not a machine file the agent manages ·
             * routing it through the Executor would prefix the path with
             * the sandbox's root and never find the file, silently reading
             * as "nothing is ever outdated" forever, with nothing to complain.
             */
            /*
             * The stat cache is always cleared before reading —
             * `phpcp-agentd` is a long-lived process, so a value PHP
             * cached from an earlier run would linger across an upgrade,
             * and this job would keep answering "nothing changed" forever
             * even after the template was genuinely replaced.
             */
            clearstatcache(true, $template);

            $source = @filemtime($template);

            if ($source === false) {
                continue;   // This template doesn't exist in the installed version — not this job's concern
            }

            $target = $executor->stat($executor->path($generated));

            if ($target === null || $source > $target['mtime']) {
                return true;
            }
        }

        return false;
    }

    private function drift(Executor $executor, string $certPath): bool
    {
        try {
            $conf = $executor->readFile($executor->path(MailboxManager::DOVECOT_CONF));
        } catch (\Throwable) {
            // Unreadable or doesn't exist yet = Dovecot was never told about this certificate
            return true;
        }

        return !str_contains($conf, 'ssl_cert = <' . $certPath);
    }

    /**
     * Nothing needed doing — still a success, not an error
     *
     * @param array<string,mixed>|null $certificate
     * @return array<string,mixed>
     */
    private function idle(string $message, ?array $certificate = null): array
    {
        return [
            'found' => $certificate !== null,
            'changed' => false,
            'certificate' => $certificate,
            'message' => $message,
        ];
    }
}
