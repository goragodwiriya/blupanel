<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use Phpcp\Agent\Actor;
use Phpcp\Agent\Client;
use Phpcp\Agent\SelfProtection;
use Phpcp\Security\AuditLog;
use Phpcp\Support\Translator;

/**
 * Where every service in the system comes from, built lazily
 *
 * Deliberately a class with plainly typed methods rather than a general string-keyed
 * DI container — a project this size gains nothing from that kind of container, but
 * loses a lot of clarity, and the IDE can no longer follow the types, which matters
 * for code that has to be checked for security.
 */
final class App
{
    private ?Db $db = null;
    private ?Logger $logger = null;
    private ?Client $agent = null;
    private ?AuditLog $audit = null;

    /**
     * The language of the request currently running — set by HttpKernel from the
     * cookie the SPA writes
     *
     * Defaults to the machine's own language (config `locale`) because work that
     * doesn't come from a browser — notification emails, CLI commands, scheduled
     * jobs — has no user to ask.
     */
    private ?string $locale = null;

    private function __construct(public readonly Config $config)
    {
    }

    /** The language used to answer this request */
    public function locale(): string
    {
        return $this->locale ?? $this->config->string('locale', 'en');
    }

    /**
     * Sets the request's language — an unrecognised value falls back to the
     * machine's language rather than failing the request
     *
     * This value comes from a cookie the user controls, so it always has to pass a
     * format filter before being turned into a catalogue filename.
     *
     * **Always overwritten, even when the value is invalid** — in a process handling
     * more than one request (the test suite, or a future worker), quietly not
     * overwriting it would mean the next request inherits the previous one's
     * language.
     */
    public function setLocale(string $locale): void
    {
        $this->locale = preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : null;
    }

    /** The catalogue for the current language — the same file the SPA uses */
    public function translator(): Translator
    {
        return Translator::load($this->locale(), $this->config->paths->spa() . '/lang');
    }

    /**
     * Translates a message headed out to a reader
     *
     * @param array<string,string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translator()->get($key, $params);
    }

    public static function boot(string $root = PHPCP_ROOT): self
    {
        $config = Config::load($root);
        $app = new self($config);

        // The portable layout keeps the panel's own files inside the project
        // directory — SelfProtection has to be told, or the file manager could edit
        // the panel's own files
        SelfProtection::protectAlso(
            $config->paths->etc,
            $config->paths->data,
            $config->paths->log,
            $config->paths->run,
            $root . '/src',
            $root . '/bin',
            $root . '/views',
            $root . '/db',
        );

        /*
         * No exception for `/var/lib/phpcp/backups` anymore.
         *
         * Backup files moved to the customer's own `<home>/backup` as of
         * PLAN-BACKUP-V2 §4.1 — what's left here is only a **temporary resting place
         * for files pulled in from an offsite destination** before it's known which
         * home they belong to (see `BackupImport`), which the agent can already reach
         * without exposing it to the file manager — so the panel's own protection
         * goes back to having no exception at all.
         */

        return $app;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->config->paths->database());

            // A value set from the screen must override the one in config.php — done
            // here because this is the very first point the database is ready. If
            // the table doesn't exist yet (before migration), skip quietly and use
            // the file's value as before rather than failing the whole system.
            try {
                Config::useStoredSettings(
                    (new \Phpcp\Domain\SettingsRepository($this->db))->all(),
                );
            } catch (\Throwable) {
                // The settings table doesn't exist yet — normal on a freshly installed machine
            }
        }

        return $this->db;
    }

    public function logger(string $channel = 'panel'): Logger
    {
        return $this->logger ??= new Logger(
            $this->config->paths->logFile($channel),
            $this->config->string('log.level', 'info'),
        );
    }

    public function agent(): Client
    {
        return $this->agent ??= new Client(
            $this->config->agentSocket(),
            $this->config->int('agent.timeout', 30),
        );
    }

    public function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog(
            $this->db(),
            $this->config->paths->logFile('audit'),
        );
    }

    /** The internal actor used for CLI or cron work with no user logged in */
    public function systemActor(string $reason): Actor
    {
        return Actor::system($reason);
    }
}
