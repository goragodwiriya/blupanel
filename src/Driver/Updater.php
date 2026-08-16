<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * Self-updates, with signature verification — ARCHITECTURE §13
 *
 * A root-privileged program's own updater is the single most direct
 * takeover path that exists: whoever can inject a file into this step gets
 * root instantly on every machine it's installed on · this file therefore
 * holds to three rules, none of which are ever relaxed for convenience:
 *
 *  1. **The signature is always checked before a file is ever touched** —
 *     never after extracting it, never "checked if a signature is present"
 *     · a package with no signature is a rejected package, not a package that skips the check.
 *
 *  2. **The public key is embedded in the code**, never downloaded
 *     alongside a package · if the key were downloaded too, an attacker
 *     could simply send their own key paired with their own package, and
 *     the signature would pass every time — a check with no trusted anchor isn't a check at all.
 *
 *  3. **Downgrading is never allowed** — feeding back an old version whose
 *     already-fixed vulnerability was patched is a genuinely effective
 *     attack even when the signature is perfectly valid.
 *
 * Uses Ed25519 through ext-sodium, which already ships with PHP — no dependency on gpg at the destination.
 */
final class Updater
{
    /**
     * The publisher's public key (base64 of 32 bytes)
     *
     * Empty = no key has been set for this build yet, which makes
     * self-update entirely unusable — a correct default in that state,
     * safer than shipping a sample key whose private half anyone could already hold.
     */
    public const PUBLIC_KEY = '';

    /** The longest a download is allowed to take */
    private const TIMEOUT = 120;

    /** The largest package size accepted — guards against a giant file filling the disk */
    private const MAX_SIZE = 64 * 1024 * 1024;

    public function __construct(private readonly string $publicKey = self::PUBLIC_KEY)
    {
    }

    public function isConfigured(): bool
    {
        return $this->publicKey !== '';
    }

    /**
     * Checks whether this package can be trusted and should be installed
     *
     * Kept separate from actually installing it, so this can be tested
     * without touching the machine's filesystem at all, and so `phpcp
     * self-update --check` can report the result without changing anything.
     *
     * @param string $archive   the package's file content
     * @param string $signature the Ed25519 signature, base64-encoded
     */
    public function verify(string $archive, string $signature, string $version, string $current): void
    {
        if (!$this->isConfigured()) {
            throw new ValidationError(
                'This build has no public key embedded, so a package signature cannot be verified — '
                . 'self-update is disabled for safety — update by installing fresh from a trusted source instead',
            );
        }

        if (!extension_loaded('sodium')) {
            throw new ValidationError('The sodium extension is not available, so a signature cannot be verified');
        }

        if ($archive === '') {
            throw new ValidationError('The package is empty');
        }

        if (strlen($archive) > self::MAX_SIZE) {
            throw new ValidationError('The package exceeds the accepted size');
        }

        $key = base64_decode($this->publicKey, true);
        $sig = base64_decode($signature, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ValidationError('The embedded public key is malformed');
        }

        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ValidationError('Malformed signature — package rejected');
        }

        if (!sodium_crypto_sign_verify_detached($sig, $archive, $key)) {
            throw new ValidationError(
                "The signature doesn't match the package — the file may have been altered in transit, "
                . 'or did not come from the genuine publisher — the entire update was cancelled',
            );
        }

        $this->assertUpgrade($version, $current);
    }

    /**
     * Downgrading is never allowed, even with a perfectly valid signature
     *
     * An old package that was once genuinely signed keeps a valid signature
     * forever — an attacker who can intercept the connection could send
     * back an old version with an already-patched vulnerability to be
     * reinstalled · a signature alone can't solve this; the version has to be compared as well.
     */
    public function assertUpgrade(string $version, string $current): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1) {
            throw new ValidationError("The package's version number is malformed");
        }

        if (version_compare($version, $current, '<')) {
            throw new ValidationError(sprintf(
                'The package is version %s, which is older than what is currently installed (%s) — rejected '
                . 'to prevent reverting to a version with a known vulnerability',
                $version,
                $current,
            ));
        }

        if (version_compare($version, $current, '=')) {
            throw new ValidationError(sprintf('Version %s is already installed', $current));
        }
    }

    /**
     * Downloads data from a URL, which must be HTTPS only
     *
     * HTTPS is enforced here, rather than hoping whoever configured the URL
     * used https on their own — even with a signature already guarding
     * against file tampering, HTTP would let an eavesdropper learn which
     * machine is running which version, exactly the kind of information used to pick an attack target.
     */
    public function fetch(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ValidationError('The package URL must be https:// only');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationError('The package URL is malformed');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new ExecutionFailed('Failed to start the download');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,   // A redirect to http:// would bypass the HTTPS enforcement above
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'phpcp/' . PHPCP_VERSION,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new ExecutionFailed('Download failed: ' . $error);
        }

        if ($status !== 200) {
            throw new ExecutionFailed("Download failed: server responded with status {$status}");
        }

        return (string) $body;
    }

    /**
     * Reads the latest release's data from the manifest file
     *
     * @return array{version:string,url:string,signature:string,notes:string}
     */
    public function parseManifest(string $json): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new ValidationError('The release manifest is malformed');
        }

        foreach (['version', 'url', 'signature'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                throw new ValidationError("The release manifest is missing field {$field}");
            }
        }

        return [
            'version' => $data['version'],
            'url' => $data['url'],
            'signature' => $data['signature'],
            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : '',
        ];
    }
}
