<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Template;

/**
 * Signs outbound mail with DKIM — PLAN-MAIL phase M3
 *
 * ## Why this needs to exist
 *
 * Mail with no DKIM signature lands in Gmail's/Outlook's spam folder almost
 * every time, no matter how correct SPF is · DKIM is a digital signature
 * attached to the mail — the destination fetches a public key from the
 * domain's DNS to verify the mail genuinely left a machine the domain owner
 * authorized, and wasn't altered along the way.
 *
 * ## Why rspamd, not OpenDKIM
 *
 * rspamd is a single daemon that does DKIM signing, spam filtering, and
 * rate limiting all at once — three things that used to need three
 * programs, three config files, and three separate places to break · more
 * importantly, rspamd reads its key from **a path containing a variable**
 * (`/var/lib/rspamd/dkim/$domain.key`), so adding a new domain never
 * requires editing a config file at all — just drop the key file in place and it's done.
 */
final class DkimManager
{
    /** The selector name used for every domain — part of the DNS record's own name */
    public const SELECTOR = 'phpcp';

    private const KEY_DIR = '/var/lib/rspamd/dkim';
    private const SIGNING_CONF = '/etc/rspamd/local.d/dkim_signing.conf';

    public function __construct(private readonly Template $templates)
    {
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path('/etc/rspamd'));
    }

    public function keyPath(string $domain): string
    {
        return self::KEY_DIR . '/' . $domain . '.key';
    }

    /**
     * Creates a domain's key if it doesn't have one yet, and returns the value that needs to go into DNS
     *
     * **Never overwrites an existing key** — a new key means the DNS record
     * has to change to match, and until it does, every mail's signature
     * check fails, which is worse than not signing at all.
     *
     * @return array{selector:string,record:string,created:bool}
     */
    public function ensureKey(Executor $executor, string $domain): array
    {
        $path = $executor->path($this->keyPath($domain));
        $created = false;

        if (!$executor->exists($path)) {
            $executor->makeDirectory($executor->path(self::KEY_DIR), 0750);

            /*
             * **Generated with openssl, never `rspamadm dkim_keygen`**
             *
             * `rspamadm` is written in LuaJIT, which needs to request
             * memory that's both writable and executable · the agent runs
             * under `MemoryDenyWriteExecute=yes`, so this instantly blows
             * up with "PANIC: unprotected error in call to Lua API
             * (runtime code generation failed, restricted kernel?)" (found
             * on the real production machine, 2026-08-12).
             *
             * Relaxing MemoryDenyWriteExecute for one single tool isn't
             * worth it — a DKIM key is just an ordinary RSA key, which
             * `openssl genrsa` produces identically, and rspamd already
             * reads that PEM file directly · the same reasoning behind why
             * the agent disables pcre.jit instead of relaxing this same rule.
             */
            $result = $executor->exec([
                $executor->path('/usr/bin/openssl'), 'genrsa',
                '-out', $path,
                '2048',
            ], timeout: 60);

            if (!$result->ok()) {
                throw new ExecutionFailed('Failed to generate a DKIM key: ' . trim($result->stderr));
            }

            // rspamd reads the key as its own user — this file is a secret that could forge signatures if it leaked
            $executor->exec(['/bin/chown', '-R', '_rspamd:_rspamd', $executor->path(self::KEY_DIR)], timeout: 15);
            $executor->changeMode($path, 0600);

            $created = true;
        }

        return [
            'selector' => self::SELECTOR,
            'record' => $this->publicRecord($executor, $domain),
            'created' => $created,
        ];
    }

    /** Deletes the key of a domain that's had mail turned off */
    public function removeKey(Executor $executor, string $domain): void
    {
        $path = $executor->path($this->keyPath($domain));

        if ($executor->exists($path)) {
            $executor->exec(['/bin/rm', '-f', $path], timeout: 15);
        }
    }

    /**
     * The value that needs to go into the TXT record of `<selector>._domainkey.<domain>`
     *
     * Reads the public key directly from the private key file, instead of
     * keeping the `.txt` file rspamadm generates — that file can go
     * missing, but the private key can't (every signature would stop
     * working), so this always derives it from the more trustworthy source.
     */
    private function publicRecord(Executor $executor, string $domain): string
    {
        $result = $executor->exec([
            $executor->path('/usr/bin/openssl'), 'rsa',
            '-in', $executor->path($this->keyPath($domain)),
            '-pubout', '-outform', 'PEM',
        ], timeout: 30);

        if (!$result->ok()) {
            return '';
        }

        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $result->stdout) ?? '';

        return 'v=DKIM1; k=rsa; p=' . $body;
    }

    /**
     * rspamd's config file — written once, never needs editing when a domain is added
     *
     * @return array<string,string>
     */
    public function configFiles(): array
    {
        return [
            self::SIGNING_CONF => $this->templates->render('rspamd/dkim_signing.conf.tpl', [
                'KEY_DIR' => self::KEY_DIR,
                'SELECTOR' => self::SELECTOR,
                'GENERATED_AT' => date('Y-m-d H:i:s'),
            ]),
        ];
    }

    public function apply(Executor $executor): void
    {
        $transaction = new ConfigTransaction($executor);

        foreach ($this->configFiles() as $path => $contents) {
            $transaction->write($path, $contents, 0644);
        }

        $transaction->commit(static fn (): array => [true, '']);

        /*
         * rspamd's own command is tried first — finishes in a fraction of a
         * second and doesn't cut any connection · `systemctl restart` for
         * rspamd can take longer than the agent is willing to wait on a
         * resource-limited machine, and the whole "turn on mail" command
         * would then fail even though the files were already written completely
         * (the same lesson learned from postfix/dovecot in phase M1).
         */
        // `systemctl reload` sends rspamd a SIGHUP to re-read its config — fast, and doesn't stop the service
        //
        // **Never use `rspamadm control reload`** — in real testing it made
        // rspamd shut its whole process down, and mail sent after that went
        // out silently unsigned (milter_default_action = accept means mail
        // still gets sent, just without a signature)
        if ($executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', 'rspamd'], timeout: 15)->ok()) {
            return;
        }

        $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'rspamd'], timeout: 20);
    }
}
