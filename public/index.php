<?php

declare(strict_types=1);

/**
 * The front controller — layer 1's single entry point
 *
 * The document root points only at the public/ folder, so the code in src/,
 * the config file, and the database all sit outside anywhere the web server can reach directly
 */

require dirname(__DIR__) . '/bootstrap.php';

use Phpcp\Kernel\App;
use Phpcp\Kernel\HttpKernel;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

try {
    $app = App::boot();
    $request = Request::capture($app->config);

    // Restricts which IPs can reach the panel — checked before everything else, since it's the cheapest check (SECURITY §2.1)
    $allowlist = $app->config->list('panel.ip_allowlist');
    if ($allowlist !== [] && !ipAllowed($request->ip, $allowlist)) {
        $app->logger()->warn('Rejected access from an IP outside the allowlist', ['ip' => $request->ip]);

        (Response::text($app->t('Access from this address is not allowed'), 403))->send();
        exit;
    }

    (new HttpKernel($app))->handle($request)->send();
} catch (Throwable $e) {
    // An error that happened before the kernel was ready (broken config, database unreachable)
    error_log('[phpcp] bootstrap failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>The system is not ready</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem">'
        . '<h1 style="font-size:1.25rem">The system is not ready</h1>'
        . '<p>An error occurred while starting up the system. Please check with the <code>phpcp doctor</code> command at the machine itself.</p>';
    exit;
}

/**
 * Checks whether an IP is in the allowlist — supports both a single IP and CIDR
 *
 * @param list<string> $allowlist
 */
function ipAllowed(string $ip, array $allowlist): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    foreach ($allowlist as $rule) {
        if (!str_contains($rule, '/')) {
            if ($ip === $rule) {
                return true;
            }
            continue;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $subnetPacked = @inet_pton($subnet);

        if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
            continue;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && strncmp($packed, $subnetPacked, $fullBytes) !== 0) {
            continue;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
        if ((ord($packed[$fullBytes]) & $mask) === (ord($subnetPacked[$fullBytes]) & $mask)) {
            return true;
        }
    }

    return false;
}
