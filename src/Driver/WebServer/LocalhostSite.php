<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

/**
 * The dev machine's http://localhost site — not a website in the system
 *
 * **Why this isn't a normal website in the database:** the name `localhost`
 * has no dot in it, so it can't pass the domain-name validator (and
 * shouldn't — a dotless name could become a vhost filename that collides
 * with the system's own) · and a website in the system has to have an
 * owner, a quota, its own FPM pool — which would mean a developer's own
 * working folder gets `chown -R`'d to belong to a customer account.
 *
 * So here it's just "one more machine-level config file" for whichever mode
 * is in use — rewritten every time config files are generated, so it
 * survives both a reinstall and a mode switch, the two events that make a
 * hand-written vhost silently disappear.
 */
final class LocalhostSite
{
    public function __construct(
        /** The folder being served — comes from `sites.localhost_docroot` */
        public readonly string $docroot,
        /** The PHP version used to pick the distro's standard FPM pool */
        public readonly string $phpVersion,
    ) {
    }

    /**
     * The distro's standard pool (www-data), never a customer account's own pool
     *
     * The dev folder doesn't belong to any customer — borrowing someone's
     * pool would mean that folder has to sit inside that account's
     * `open_basedir`, and any file PHP creates there would end up owned by that account.
     */
    public function fpmSocket(): string
    {
        return '/run/php/php' . $this->phpVersion . '-fpm.sock';
    }

    public function errorLog(): string
    {
        return '/var/log/phpcp/localhost-error.log';
    }

    public function accessLog(): string
    {
        return '/var/log/phpcp/localhost-access.log';
    }
}
