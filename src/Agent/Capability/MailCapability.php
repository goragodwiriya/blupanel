<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;

/**
 * Base for every capability that touches mailboxes — PLAN-MAIL phase M1
 *
 * **What every one of them has to do the same way after changing the
 * database:** rewrite the whole set of lookup tables from a machine-wide
 * view, then rewrite `main.cf`/`master.cf` to match whether any domain has
 * mail turned on.
 *
 * Left for each one to do on its own, sooner or later one forgets to write
 * one of the files, and the machine ends up with the database saying one
 * thing while Postfix does another — which for mail means a deleted mailbox
 * still receives mail, or a freshly created one doesn't exist yet.
 */
abstract class MailCapability implements Capability
{
    public function isMutating(): bool
    {
        return true;
    }

    /** Everything that touches mailboxes is the same permission — a site owner manages their own domain's mail */
    public function permission(): string
    {
        return 'mail.manage';
    }

    protected function repository(Context $context): MailboxRepository
    {
        return new MailboxRepository($context->db);
    }

    /**
     * The mail hostname this machine actually uses
     *
     * **Has to be the single source of truth for the whole system** — this
     * value is used in three places that must always agree: writing
     * `myhostname` into Postfix · deciding which certificate covers this
     * name · reporting readiness.
     *
     * Where each of those reads it on its own, `mail.cert` and the readiness
     * page read `mail.hostname` directly, while `sync()` can fall back to
     * `mail.from` · a machine that never filled in the hostname field ends up
     * with a Postfix that announces the correct name, but the "bind
     * certificate" button reports "hostname not set yet" and does nothing —
     * two parts of the same system disagreeing on what the machine is called.
     */
    protected static function mailHostname(SettingsRepository $settings): string
    {
        $hostname = trim($settings->get('mail.hostname'));

        if ($hostname !== '') {
            return $hostname;
        }

        // The sender address's domain part is the best guess available — the machine owner already filled it in
        $from = trim($settings->get('mail.from'));

        if ($from !== '' && str_contains($from, '@')) {
            return substr($from, strpos($from, '@') + 1);
        }

        return gethostname() ?: '';
    }

    protected function mailboxes(Context $context): MailboxManager
    {
        return new MailboxManager(new Template($context->config->paths->templates()));
    }

    /**
     * Writes every config file to match the database as it stands right now
     *
     * Always called after finishing a database change · order matters:
     * `main.cf` has to know first whether mail receiving is on at all (it
     * decides whether to listen on port 25), and only then are the lookup
     * tables that refer back to it written.
     *
     * **This is the only path allowed to write `main.cf`** — including
     * clicking to save outbound mail settings from the settings page
     * (`mail.apply`) · writing from anywhere else would mean remembering on
     * its own whether mail hosting is turned on, and forgetting that even
     * once produces a `main.cf` with no receiving section at all: every
     * mailbox on the machine silently stops receiving mail, while the user
     * only clicked to save a sender address.
     *
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    protected function sync(Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domains = $repository->enabledDomains();

        $settings = new SettingsRepository($context->db);
        $hostname = self::mailHostname($settings);

        /*
         * The mail hostname must be a full name with a dot in it — the
         * receiving server uses this value at greeting time (EHLO), and many
         * of them reject a dotless name outright.
         *
         * Plenty of machines have their hostname set to a short name
         * (nearly every container does) — this message therefore has to say
         * how to fix it, not just that the value is wrong.
         */
        if (!str_contains($hostname, '.')) {
            throw new ValidationError(
                'The mail hostname must be a full name with a dot in it (e.g. mail.example.com) — got "'
                . ($hostname !== '' ? $hostname : 'empty')
                . '" · set mail.hostname on the settings page, or change the machine\'s hostname to a full name',
            );
        }

        $postfix = new MailManager(new Template($context->config->paths->templates()));

        $outbound = $postfix->apply($executor, [
            'mode' => $settings->get('mail.mode') ?: 'local',
            'hostname' => $hostname,
            'from' => $settings->get('mail.from'),
            'relay_host' => $settings->get('mail.relay_host'),
            'relay_port' => $settings->int('mail.relay_port'),
            'relay_user' => $settings->get('mail.relay_user'),
            'relay_password' => $settings->get('mail.relay_password'),
            'relay_tls' => $settings->bool('mail.relay_tls'),
            'hosting' => $domains !== [],
            // Is the machine's own name also a mailbox domain? — if so, it must never go into mydestination
            'virtual_hostname' => in_array($hostname, $domains, true),
            'tls_cert' => $settings->get('mail.tls_cert'),
            'tls_key' => $settings->get('mail.tls_key'),
        ], reload: false);

        $mailboxes = $this->mailboxes($context);

        /*
         * **A machine that only sends mail doesn't need Dovecot at all** —
         * and that's most machines.
         *
         * `mail.apply` (the outbound-settings save button on the settings
         * page) runs through this same path too, and those machines only
         * have Postfix, for sending notification mail · writing the mailbox
         * tables there would fail at `doveconf -n` with not a single mailbox
         * to write.
         */
        if ($domains === [] && !$mailboxes->isInstalled($executor)) {
            // reload can't change the listening port · a machine that used to listen on port 25 has to genuinely stop listening
            ($outbound['restart_required'] ?? false)
                ? $postfix->restart($executor)
                : $postfix->reload($executor);

            return ['domains' => 0, 'mailboxes' => 0, 'aliases' => 0];
        }

        $result = $mailboxes->apply(
            $executor,
            $domains,
            $repository->activeMailboxes(),
            $repository->activeAliases(),
            // Dovecot has to get the exact same certificate as Postfix — mail clients connect IMAP straight to Dovecot
            ['cert' => $settings->get('mail.tls_cert'), 'key' => $settings->get('mail.tls_key')],
        );

        // Turning mail hosting on or off for the first time changes which port Postfix listens on, which reload can't do
        if ($outbound['restart_required'] ?? false) {
            $postfix->restart($executor);
        }

        return $result;
    }

    /**
     * Looks up the domain the caller specified, checking it genuinely exists
     *
     * @return array<string,mixed>
     */
    protected function domainOrFail(Context $context, string $domain): array
    {
        $row = $this->repository($context)->findDomain($domain);

        if ($row === null) {
            throw new ValidationError('Domain ' . $domain . ' was not found in the system');
        }

        return $row;
    }
}
