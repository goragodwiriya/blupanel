<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;

/**
 * The web server abstraction layer — ARCHITECTURE §10
 *
 * v1 ships ApacheDriver (matches the target machine), with NginxDriver
 * arriving in phase 5 · both use PHP-FPM through a unix socket the same
 * way, so the PHP-version-switching logic can be fully shared between them without duplication.
 */
interface WebServerDriver
{
    public function name(): string;

    /** This web server's systemd unit name */
    public function unit(): string;

    /** The system user the web server runs as — used to set the FPM socket's owner */
    public function runAsUser(): string;

    /**
     * The web server process's group
     *
     * A website's files are set to <website's user>:<this group>, so the
     * web server can read and traverse the directory while other websites'
     * users still can't get in.
     */
    public function runAsGroup(): string;

    /**
     * Builds a vhost file's content from a template, without writing it to disk
     *
     * Takes an `Executor` because "a path that appears inside the config
     * file's own content" also has to be mapped to match the current mode,
     * exactly like the file's own path — otherwise in sandbox mode this
     * would produce a vhost pointing at the real `/srv/phpcp/...`, which
     * doesn't exist there, and configtest would fail even though the file is correct.
     */
    public function renderVhost(Site $site, Executor $executor): string;

    /**
     * This website's primary vhost file path (a real-system path, not yet mapped)
     *
     * "Primary" = the file belonging to the server that genuinely receives
     * requests from the internet — used to show an admin where this site is
     * configured · a mode with multiple layers has more files than this — see vhostFiles().
     */
    public function vhostPath(Site $site): string;

    /**
     * Every config file belonging to this website — path => content
     *
     * This method exists because nginx-proxy mode writes **two files per
     * site** (nginx in front, Apache behind) — writing/deleting must always
     * cover both together in the same transaction, or an orphaned vhost is
     * left pointing at a backend that no longer exists, and every request gets a 502.
     *
     * A single-layer driver returns just one entry: [vhostPath() => renderVhost()]
     *
     * @return array<string,string>
     */
    public function vhostFiles(Site $site, Executor $executor): array;

    /**
     * This mode's machine-level files — not belonging to any one website
     *
     * Rewritten every time config files are generated, because each mode
     * overwrites the other mode's files (the clearest example is Apache's
     * `ports.conf`, which nginx-proxy mode moves to 8080) — if a mode never
     * wrote its own files back, switching back to it would leave the
     * machine stuck halfway between modes.
     *
     * Deliberately kept separate from vhostPaths() — deleting one website must never delete a file the whole machine shares.
     *
     * @return array<string,string> path => content
     */
    public function globalFiles(Executor $executor): array;

    /**
     * Every file path belonging to this website — used when deleting
     *
     * Kept separate from vhostFiles() because deletion shouldn't have to
     * render content first · a site about to be deleted might already fail
     * to render (e.g. its certificate was already removed), which would make it impossible to ever delete at all.
     *
     * @return list<string>
     */
    public function vhostPaths(Site $site): array;

    /**
     * Validates the whole config's correctness
     *
     * @return array{0:bool,1:string} [did it pass, the validation tool's own output]
     */
    public function testConfig(Executor $executor): array;

    /** Tells the web server to reload its config without cutting off connections already in progress */
    public function reload(Executor $executor): void;

    public function isInstalled(Executor $executor): bool;

    /**
     * Turns on any module/extension the system's generated config files require
     *
     * Lives in the interface because each web server has different
     * requirements — nginx has no runtime enable/disable module system, so it can simply return [].
     *
     * @return list<string> the names of modules just turned on in this pass
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array;
}
