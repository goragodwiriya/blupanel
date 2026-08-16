<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Pushes a backup file to an S3-compatible destination (AWS S3 or any
 * service speaking the same protocol — MinIO, Backblaze B2, DigitalOcean
 * Spaces, Cloudflare R2) — PLAN-V2 phase E1
 *
 * **Why SigV4 is signed by hand instead of using an SDK:** this project
 * deliberately has no Composer (ARCHITECTURE §2), and the AWS SDK for PHP
 * always needs it · the signer in this file uses only `hash_hmac`/`hash`,
 * which ship with PHP, and `ext-curl` to send HTTP directly, the same
 * approach as {@see \Phpcp\Driver\Updater::fetch()} and {@see \Phpcp\Driver\Notify\TelegramNotifier}.
 *
 * **Why this doesn't go through `Executor::exec()` like the other drivers
 * in this folder do:** sftp/rsync run a program on the machine, which must
 * always go through `Executor`'s simulation/permission limits, but this is
 * a direct external HTTPS API call — exactly what `TelegramNotifier` and
 * `Updater::fetch()` already do — there's nothing here for a sandbox to
 * protect (this machine is never touched) · `Executor` is still used, but only to read/write files *on this machine*.
 *
 * **File weight:** uploads/downloads stream directly to/from disk via
 * `CURLOPT_INFILE`/`CURLOPT_FILE`, never loading the whole file into memory
 * — a backup file can easily reach several GB.
 *
 * **Authenticates with a key pair only**, like sftp/rsync — `access_key` is
 * stored as an ordinary setting (not a secret; already readable from
 * anywhere with permission to call AWS, e.g. an access log), while
 * `secret_key` is encrypted at rest the same way an sftp/rsync private key is.
 *
 * **Never yet fired against a genuine S3 server** — the SigV4 algorithm was
 * written closely against AWS's own spec, and has internal tests verifying
 * its correctness (`tests/security/S3BackupDestinationTest.php`), but
 * `test()`/`push()`/`pull()` have never once been proven against a real
 * endpoint, because the dev machine has no S3 account to test with — this
 * needs at least one real run before it can be trusted to work (the same
 * caveat PLAN-V2 §6 phase E1 recorded for sftp/rsync).
 */
final class S3Destination implements Destination
{
    private const SERVICE = 's3';
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const TIMEOUT = 1800;
    private const CONNECT_TIMEOUT = 15;

    public function __construct(
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey = '',
        private readonly string $path = '',
        private readonly string $endpoint = '',
        private readonly bool $pathStyle = false,
    ) {
        if ($this->bucket === '' || preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $this->bucket) !== 1) {
            throw new ValidationError('Invalid bucket name — only lowercase letters, digits, dots, and hyphens are allowed');
        }

        if ($this->region === '') {
            throw new ValidationError('A region must be specified');
        }

        if ($this->accessKey === '') {
            throw new ValidationError('An access key must be specified');
        }

        if ($this->secretKey === '') {
            throw new ValidationError('A secret key must be specified');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->path) === 1) {
            throw new ValidationError('The destination path must not contain ..');
        }

        if ($this->endpoint !== '' && !str_starts_with($this->endpoint, 'https://')) {
            throw new ValidationError('The endpoint must be https:// only');
        }
    }

    public static function driver(): string
    {
        return 's3';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $key = $this->keyFor($remoteName);
        $realLocal = $executor->path($localPath);
        $stat = $executor->stat($realLocal);
        $size = $stat['size'] ?? null;

        if ($size === null) {
            throw new ExecutionFailed('Failed to read the source backup file\'s size: ' . $localPath);
        }

        $handle = fopen($realLocal, 'rb');

        if ($handle === false) {
            throw new ExecutionFailed('Failed to open the source backup file: ' . $localPath);
        }

        try {
            // UNSIGNED-PAYLOAD avoids reading the whole file to hash it
            // before uploading (a backup file can easily reach several GB)
            // — a value S3's own spec directly permits, not an incorrect shortcut
            $response = $this->call('PUT', $key, 'UNSIGNED-PAYLOAD', [
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => (int) $size,
            ]);
        } finally {
            fclose($handle);
        }

        $this->assertStatus($response, [200], 'Failed to push the backup file to the destination');
        $this->assertArrived($response, $realLocal, (int) $size);

        return $key;
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $realLocal = $executor->path($localPath);
        $handle = fopen($realLocal, 'wb');

        if ($handle === false) {
            throw new ExecutionFailed('Failed to open the destination file on this machine: ' . $localPath);
        }

        try {
            $response = $this->call('GET', $remotePath, hash('sha256', ''), [
                CURLOPT_FILE => $handle,
            ]);
        } finally {
            fclose($handle);
        }

        if (!$this->ok($response, [200])) {
            @unlink($realLocal);   // A half-written file is more dangerous than no file at all
            throw new ExecutionFailed('Failed to pull the backup file from the destination: ' . $this->explainError($response));
        }

        if (!$executor->exists($realLocal)) {
            throw new ExecutionFailed('The pull command succeeded, but the file was not found on this machine');
        }
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsidePath($remotePath);

        $response = $this->call('DELETE', $remotePath, hash('sha256', ''), []);

        // S3 answers 204 both when a delete succeeds and when the object
        // never existed — matching the Destination contract's requirement
        // that "a file that's already gone counts as success" with no special-case check needed
        if (!$this->ok($response, [204, 200])) {
            throw new ExecutionFailed('Failed to delete the file at the destination: ' . $this->explainError($response));
        }
    }

    public function test(Executor $executor): array
    {
        $name = '.phpcp-probe-' . bin2hex(random_bytes(4));
        $local = sys_get_temp_dir() . '/' . $name;
        $content = 'phpcp destination probe ' . time();

        $executor->writeFile($executor->path($local), $content, 0600);

        try {
            $remoteKey = $this->push($executor, $local, $name);

            $roundTrip = $local . '.back';
            $this->pull($executor, $remoteKey, $roundTrip);

            $readBack = $executor->readFile($executor->path($roundTrip));
            $executor->removePath($executor->path($roundTrip));

            if ($readBack !== $content) {
                throw new ExecutionFailed('Pushed the test file successfully, but pulling it back returned different content');
            }

            $this->delete($executor, $remoteKey);

            return [
                'bucket' => $this->bucket,
                'region' => $this->region,
                'endpoint' => $this->endpoint !== '' ? $this->endpoint : $this->defaultHost(),
                'path_style' => $this->pathStyle,
                'auth' => 'access_key',
            ];
        } finally {
            if ($executor->exists($executor->path($local))) {
                $executor->removePath($executor->path($local));
            }
        }
    }

    private function keyFor(string $name): string
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ValidationError('The destination filename must be a name only, no directory');
        }

        return $this->path === '' ? $name : rtrim($this->path, '/') . '/' . $name;
    }

    private function assertInsidePath(string $key): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            throw new ValidationError('The destination file path must not contain ..');
        }

        if ($this->path !== '' && !str_starts_with($key, rtrim($this->path, '/') . '/')) {
            throw new ValidationError('This path is outside the configured backup destination');
        }
    }

    /**
     * Confirms the uploaded object genuinely arrived complete — not just that HTTP returned 200
     *
     * Compares the ETag against the original file's MD5 when possible (a
     * single-part upload not encrypted server-side with AWS's own key — S3
     * only guarantees ETag = MD5 hex in that specific case) · if the ETag
     * isn't in that shape (multipart, or server-side encryption), this
     * falls back to comparing the size reported in the same header — weaker,
     * but still catches a half-arrived file — the same approach as `SftpDestination::assertArrived()`.
     */
    private function assertArrived(array $response, string $realLocal, int $localSize): void
    {
        $etag = trim((string) ($response['headers']['etag'] ?? ''), '"');

        if (preg_match('/^[a-f0-9]{32}$/', $etag) === 1) {
            $localMd5 = @hash_file('md5', $realLocal);

            if ($localMd5 === false || !hash_equals($localMd5, $etag)) {
                throw new ExecutionFailed('The file at the destination does not match the original (ETag mismatch) — treated as a failed push');
            }

            return;
        }

        $contentLength = $response['headers']['content-length'] ?? null;

        if ($contentLength !== null && (int) $contentLength !== $localSize) {
            throw new ExecutionFailed('The file size at the destination does not match the original — treated as a failed push');
        }
    }

    /**
     * Builds the canonical request / string-to-sign / Authorization header per the SigV4 spec
     *
     * Kept separate from `call()` so a test can call it directly through
     * reflection with no genuine network connection needed — this function
     * is entirely pure (the same input always produces the same output,
     * never touching curl or the filesystem), so the algorithm's
     * correctness can be fully verified without a real S3 account.
     *
     * @return array{host:string,canonicalUri:string,amzDate:string,canonicalRequest:string,stringToSign:string,authorization:string}
     */
    private function sign(string $method, string $key, string $payloadHash, string $amzDate): array
    {
        $dateStamp = substr($amzDate, 0, 8);
        $host = $this->requestHost();
        $canonicalUri = $this->canonicalUri($key);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',   // No query string in this set of requests
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp));

        $authorization = self::ALGORITHM . ' '
            . "Credential={$this->accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, "
            . "Signature={$signature}";

        return [
            'host' => $host,
            'canonicalUri' => $canonicalUri,
            'amzDate' => $amzDate,
            'canonicalRequest' => $canonicalRequest,
            'stringToSign' => $stringToSign,
            'authorization' => $authorization,
        ];
    }

    /**
     * Sends a request signed with AWS Signature Version 4 and returns the raw result
     *
     * @param array<int,mixed> $curlOptions command-specific extra curl options (uploading/downloading a file)
     * @return array{status:int,headers:array<string,string>,body:string,error:string}
     */
    private function call(string $method, string $key, string $payloadHash, array $curlOptions): array
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $signed = $this->sign($method, $key, $payloadHash, $amzDate);
        $host = $signed['host'];
        $canonicalUri = $signed['canonicalUri'];
        $authorization = $signed['authorization'];

        $headers = [];
        $handle = curl_init("https://{$host}{$canonicalUri}");

        if ($handle === false) {
            throw new ExecutionFailed('Failed to start a connection to S3');
        }

        curl_setopt_array($handle, $curlOptions + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $host,
                'x-amz-date: ' . $amzDate,
                'x-amz-content-sha256: ' . $payloadHash,
                'Authorization: ' . $authorization,
            ],
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false && $error === '') {
            // CURLOPT_FILE/CURLOPT_RETURNTRANSFER makes curl_exec return true,
            // not the body content, when the receiver is a file — not a failure
            $body = '';
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /** @param array{status:int,headers:array<string,string>,body:string,error:string} $response */
    private function ok(array $response, array $okStatuses): bool
    {
        return $response['error'] === '' && in_array($response['status'], $okStatuses, true);
    }

    /** @param array{status:int,headers:array<string,string>,body:string,error:string} $response */
    private function assertStatus(array $response, array $okStatuses, string $action): void
    {
        if (!$this->ok($response, $okStatuses)) {
            throw new ExecutionFailed($action . ': ' . $this->explainError($response));
        }
    }

    /** @param array{status:int,headers:array<string,string>,body:string,error:string} $response */
    private function explainError(array $response): string
    {
        if ($response['error'] !== '') {
            return $response['error'];
        }

        // S3's own error message is XML: <Error><Code>...</Code><Message>...</Message></Error>
        if (preg_match('#<Message>(.*?)</Message>#s', $response['body'], $m) === 1) {
            return "HTTP {$response['status']}: " . trim($m[1]);
        }

        return "HTTP {$response['status']}" . ($response['body'] !== '' ? ': ' . mb_substr(trim($response['body']), 0, 300) : '');
    }

    private function signingKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /** The configured endpoint's own hostname, or AWS's standard endpoint for the region */
    private function defaultHost(): string
    {
        return $this->endpoint !== ''
            ? (string) (parse_url($this->endpoint, PHP_URL_HOST) ?? $this->endpoint)
            : "s3.{$this->region}.amazonaws.com";
    }

    /**
     * The host genuinely used in a request — virtual-hosted (`bucket.host`)
     * is AWS's own default, while path-style (plain `host` + bucket in the
     * URI) is required for a provider with no wildcard DNS for every
     * bucket, e.g. a self-hosted MinIO — selectable via the `path_style` config.
     */
    private function requestHost(): string
    {
        $host = $this->defaultHost();

        return $this->pathStyle ? $host : "{$this->bucket}.{$host}";
    }

    /** URL-encodes each segment of the key per AWS SigV4's rules, keeping `/` as a separator */
    private function canonicalUri(string $key): string
    {
        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        return $this->pathStyle
            ? '/' . rawurlencode($this->bucket) . '/' . $encodedKey
            : '/' . $encodedKey;
    }
}
