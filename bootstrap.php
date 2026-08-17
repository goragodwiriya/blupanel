<?php

declare(strict_types=1);

/**
 * The shared starting point for every entry point — public/index.php, bin/phpcp, bin/phpcp-agentd
 *
 * No Composer, per decision D6, so this uses a short hand-written PSR-4
 * autoloader · the added benefit is that opcache can cache this whole file, with no directory scan at runtime
 */

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'phpcp requires PHP 8.1 or newer (found ' . PHP_VERSION . ")\n");
    exit(1);
}

define('PHPCP_ROOT', __DIR__);
define('PHPCP_VERSION', '0.1.0-dev');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Phpcp\\')) {
        return;
    }
    $file = PHPCP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Values that must be forced the same way at every entry point, regardless of how the machine's php.ini is set
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '0');
