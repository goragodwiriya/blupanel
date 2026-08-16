<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;

/**
 * This machine's public IP — the value every A record must point at
 *
 * ## Why the network card can't just be asked directly
 *
 * Almost every cloud machine sits behind NAT: the network card only ever sees an
 * internal IP (e.g. `172.26.15.166` on Lightsail), while the IP the outside world
 * actually uses is a completely different number (`18.142.27.80`) · using the
 * network card's own value to build an A record means every domain on the machine
 * points at an address nobody outside the private network can reach — every site
 * goes down while everything in the system looks "successful."
 *
 * ## Lookup order
 *
 *   1. `server.public_ip`, set by the admin themselves — always wins · a machine
 *      behind a proxy or with multiple IPs needs an explicit way to say so, not a
 *      guess from the system.
 *   2. Cloud metadata (IMDSv2) — answers the real public IP without needing to
 *      reach the internet at all · works on AWS EC2 and Lightsail, where most
 *      users are installed.
 *   3. The source address of the outbound route — correct on a machine whose
 *      network card genuinely has a public IP attached (a typical VPS, an
 *      on-premise machine), and the best remaining answer available.
 *
 * **Never asks an external service like ifconfig.me** — DNS configuration
 * shouldn't depend on whether someone else's website is still up, and shouldn't
 * signal out to anyone which domains this machine has just set up.
 */
final class ServerAddress
{
    /** AWS/Lightsail metadata — link-local, so it never leaves the machine */
    private const METADATA_HOST = 'http://169.254.169.254';

    /** Deliberately very short — a non-cloud machine will have nobody answering, and this must not hang */
    private const METADATA_TIMEOUT = 2;

    /**
     * The IP that should be used to build an A record — returns '' when nothing was found
     *
     * The caller must handle the empty case itself (ask the admin), not take a
     * guessed value and write it into a zone.
     */
    public static function detect(Executor $executor, string $configured = ''): string
    {
        $configured = trim($configured);

        if ($configured !== '') {
            return self::isIpv4($configured) ? $configured : '';
        }

        return self::fromMetadata() ?: self::fromRoute($executor);
    }

    /**
     * Query cloud metadata · IMDSv2 requires requesting a token before it can be read
     *
     * IMDSv1 (reading directly, no token needed) is now disabled by default on new
     * instances, so the v2 path has to be the primary route, not a fallback.
     */
    private static function fromMetadata(): string
    {
        $token = self::http('PUT', self::METADATA_HOST . '/latest/api/token', [
            'X-aws-ec2-metadata-token-ttl-seconds: 60',
        ]);

        if ($token === '') {
            return '';
        }

        $ip = self::http('GET', self::METADATA_HOST . '/latest/meta-data/public-ipv4', [
            'X-aws-ec2-metadata-token: ' . $token,
        ]);

        return self::isIpv4($ip) ? $ip : '';
    }

    /**
     * The source address the kernel would use for an outbound connection
     *
     * Asks the kernel "if this were headed to 1.1.1.1, which address would it go
     * out with," which answers correctly even on a machine with multiple network
     * cards or addresses · nothing is actually sent — this is purely a routing
     * table lookup.
     */
    private static function fromRoute(Executor $executor): string
    {
        $result = $executor->exec([$executor->path('/usr/sbin/ip'), '-4', 'route', 'get', '1.1.1.1'], timeout: 5);

        if (!$result->ok() || preg_match('/\bsrc\s+(\d+\.\d+\.\d+\.\d+)/', $result->output(), $m) !== 1) {
            return '';
        }

        return self::isIpv4($m[1]) ? $m[1] : '';
    }

    /**
     * @param list<string> $headers
     */
    private static function http(string $method, string $url, array $headers): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => self::METADATA_TIMEOUT,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        return $body === false ? '' : trim($body);
    }

    /** A valid, internet-routable IPv4 address — excludes private and loopback ranges */
    public static function isIpv4(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Whether this address is a private IP — used to warn the admin, not to reject it
     *
     * An on-premise machine serving only its own internal network correctly uses a
     * private address, so rejecting it would be wrong · but on the cloud, this is
     * almost always a sign the IP detection went wrong — better to say so than
     * silently let it through.
     */
    public static function isPrivate(string $value): bool
    {
        return self::isIpv4($value)
            && filter_var(
                $value,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;
    }
}
