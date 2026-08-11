<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * ส่งไฟล์สำรองไปที่ปลายทางแบบ S3 (AWS S3 หรือบริการที่พูดโปรโตคอลเดียวกัน
 * เช่น MinIO, Backblaze B2, DigitalOcean Spaces, Cloudflare R2) — PLAN-V2 เฟส E1
 *
 * **ทำไมเซ็น SigV4 เอง ไม่ใช้ SDK:** โปรเจกต์นี้ไม่มี Composer โดยตั้งใจ (ARCHITECTURE §2)
 * และ AWS SDK for PHP ต้องมันเสมอ · ตัวเซ็นในไฟล์นี้ใช้แค่ `hash_hmac`/`hash` ที่มากับ PHP
 * และ `ext-curl` สำหรับส่ง HTTP ตรง ๆ ตามแบบเดียวกับ {@see \Phpcp\Driver\Updater::fetch()}
 * และ {@see \Phpcp\Driver\Notify\TelegramNotifier}
 *
 * **ทำไมไม่ผ่าน `Executor::exec()` เหมือน driver อื่นในโฟลเดอร์นี้:** sftp/rsync สั่ง
 * โปรแกรมบนเครื่องซึ่งต้องผ่านการจำลอง/จำกัดสิทธิ์ของ `Executor` เสมอ แต่ที่นี่คือ
 * การเรียก HTTPS API ภายนอกโดยตรง — เหมือนที่ `TelegramNotifier` และ `Updater::fetch()`
 * ทำอยู่แล้ว ไม่มีอะไรให้ sandbox ป้องกัน (เครื่องนี้ไม่ถูกแตะเลย) `Executor` ยังถูกใช้
 * เพื่ออ่าน/เขียนไฟล์ *ในเครื่อง* เท่านั้น
 *
 * **น้ำหนักไฟล์:** อัปโหลด/ดาวน์โหลดสตรีมตรงเข้า-ออกดิสก์ผ่าน `CURLOPT_INFILE`/
 * `CURLOPT_FILE` ไม่โหลดทั้งไฟล์ขึ้นหน่วยความจำ — ไฟล์สำรองมีสิทธิ์ใหญ่เป็น GB
 *
 * **ยืนยันตัวตนด้วยคู่กุญแจเท่านั้น** เหมือน sftp/rsync — `access_key` เก็บเป็นค่าตั้ง
 * ธรรมดา (ไม่ใช่ความลับ อ่านได้จากทุกที่ที่มีสิทธิ์เรียก AWS อยู่แล้วเช่น access log)
 * ส่วน `secret_key` เข้ารหัสเก็บแบบเดียวกับ private key ของ sftp/rsync
 *
 * **ยังไม่เคยยิงไปเซิร์ฟเวอร์ S3 จริง** — อัลกอริทึม SigV4 เขียนตามสเปกของ AWS อย่าง
 * ละเอียด และมีเทสต์ตรวจความถูกต้องภายใน (`tests/security/S3BackupDestinationTest.php`)
 * แต่ `test()`/`push()`/`pull()` ยังไม่เคยพิสูจน์กับ endpoint จริงสักครั้งเพราะเครื่องพัฒนา
 * ไม่มีบัญชี S3 ให้ทดสอบ — ต้องยิงจริงอย่างน้อยหนึ่งครั้งก่อนเชื่อว่าใช้งานได้ (เหมือนที่
 * PLAN-V2 §6 เฟส E1 บันทึกไว้สำหรับ sftp/rsync)
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
            throw new ValidationError('ชื่อ bucket ไม่ถูกต้อง — ใช้ได้เฉพาะตัวเล็ก ตัวเลข จุด และขีด');
        }

        if ($this->region === '') {
            throw new ValidationError('ต้องระบุ region');
        }

        if ($this->accessKey === '') {
            throw new ValidationError('ต้องระบุ access key');
        }

        if ($this->secretKey === '') {
            throw new ValidationError('ต้องระบุ secret key');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->path) === 1) {
            throw new ValidationError('เส้นทางปลายทางต้องไม่มี ..');
        }

        if ($this->endpoint !== '' && !str_starts_with($this->endpoint, 'https://')) {
            throw new ValidationError('endpoint ต้องเป็น https:// เท่านั้น');
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
            throw new ExecutionFailed('อ่านขนาดไฟล์สำรองต้นทางไม่ได้: ' . $localPath);
        }

        $handle = fopen($realLocal, 'rb');

        if ($handle === false) {
            throw new ExecutionFailed('เปิดไฟล์สำรองต้นทางไม่ได้: ' . $localPath);
        }

        try {
            // UNSIGNED-PAYLOAD กันไม่ต้องอ่านทั้งไฟล์มา hash ก่อนอัปโหลด (ไฟล์สำรอง
            // มีสิทธิ์ใหญ่เป็น GB) — เป็นค่าที่ S3 ยอมรับตามสเปกโดยตรง ไม่ใช่ทางลัดที่ผิด
            $response = $this->call('PUT', $key, 'UNSIGNED-PAYLOAD', [
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => (int) $size,
            ]);
        } finally {
            fclose($handle);
        }

        $this->assertStatus($response, [200], 'ส่งไฟล์สำรองไปยังปลายทางไม่สำเร็จ');
        $this->assertArrived($response, $realLocal, (int) $size);

        return $key;
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $realLocal = $executor->path($localPath);
        $handle = fopen($realLocal, 'wb');

        if ($handle === false) {
            throw new ExecutionFailed('เปิดไฟล์ปลายทางบนเครื่องนี้ไม่ได้: ' . $localPath);
        }

        try {
            $response = $this->call('GET', $remotePath, hash('sha256', ''), [
                CURLOPT_FILE => $handle,
            ]);
        } finally {
            fclose($handle);
        }

        if (!$this->ok($response, [200])) {
            @unlink($realLocal);   // ไฟล์ครึ่งเดียวอันตรายกว่าไม่มีไฟล์เลย
            throw new ExecutionFailed('ดึงไฟล์สำรองจากปลายทางไม่สำเร็จ: ' . $this->explainError($response));
        }

        if (!$executor->exists($realLocal)) {
            throw new ExecutionFailed('คำสั่งดึงไฟล์สำเร็จ แต่ไม่พบไฟล์บนเครื่องนี้');
        }
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsidePath($remotePath);

        $response = $this->call('DELETE', $remotePath, hash('sha256', ''), []);

        // S3 ตอบ 204 ทั้งกรณีลบสำเร็จและกรณีไม่มีวัตถุนั้นอยู่แล้ว — สอดคล้องกับสัญญา
        // ของ Destination ที่ต้อง "ไฟล์ที่ไม่มีอยู่แล้วถือว่าสำเร็จ" โดยไม่ต้องเช็คพิเศษ
        if (!$this->ok($response, [204, 200])) {
            throw new ExecutionFailed('ลบไฟล์ที่ปลายทางไม่สำเร็จ: ' . $this->explainError($response));
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
                throw new ExecutionFailed('ส่งไฟล์ทดสอบได้ แต่ดึงกลับมาแล้วเนื้อหาไม่ตรง');
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
            throw new ValidationError('ชื่อไฟล์ปลายทางต้องเป็นชื่อล้วน ไม่มีไดเรกทอรี');
        }

        return $this->path === '' ? $name : rtrim($this->path, '/') . '/' . $name;
    }

    private function assertInsidePath(string $key): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            throw new ValidationError('เส้นทางไฟล์ปลายทางต้องไม่มี ..');
        }

        if ($this->path !== '' && !str_starts_with($key, rtrim($this->path, '/') . '/')) {
            throw new ValidationError('เส้นทางนี้อยู่นอกปลายทางสำรองที่กำหนดไว้');
        }
    }

    /**
     * ยืนยันว่าวัตถุที่อัปโหลดครบถ้วนจริง — ไม่ใช่แค่ HTTP 200
     *
     * เทียบ ETag กับ MD5 ของไฟล์ต้นฉบับเมื่อทำได้ (อัปโหลดชิ้นเดียวไม่เข้ารหัสฝั่งเซิร์ฟเวอร์
     * ด้วยกุญแจของ AWS เอง — S3 การันตีว่า ETag = MD5 hex เฉพาะกรณีนี้) ถ้า ETag ไม่ใช่รูปแบบ
     * นั้น (มัลติพาร์ตหรือเข้ารหัสฝั่งเซิร์ฟเวอร์) ถอยไปเทียบขนาดที่ได้จาก header เดียวกัน
     * ซึ่งอ่อนกว่าแต่ยังจับกรณีส่งไปครึ่งเดียวได้ — แบบเดียวกับ `SftpDestination::assertArrived()`
     */
    private function assertArrived(array $response, string $realLocal, int $localSize): void
    {
        $etag = trim((string) ($response['headers']['etag'] ?? ''), '"');

        if (preg_match('/^[a-f0-9]{32}$/', $etag) === 1) {
            $localMd5 = @hash_file('md5', $realLocal);

            if ($localMd5 === false || !hash_equals($localMd5, $etag)) {
                throw new ExecutionFailed('ไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ (ETag ไม่ตรง) — ถือว่าส่งไม่สำเร็จ');
            }

            return;
        }

        $contentLength = $response['headers']['content-length'] ?? null;

        if ($contentLength !== null && (int) $contentLength !== $localSize) {
            throw new ExecutionFailed('ขนาดไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ — ถือว่าส่งไม่สำเร็จ');
        }
    }

    /**
     * ประกอบ canonical request / string-to-sign / Authorization header ตามสเปก SigV4
     *
     * แยกออกจาก `call()` เพื่อให้เทสต์เรียกตรง ๆ ผ่าน reflection ได้โดยไม่ต้องมี
     * การเชื่อมต่อเครือข่ายจริง — ฟังก์ชันนี้เป็น pure function ล้วน (input เดียวกัน
     * ได้ output เดียวกันเสมอ ไม่แตะ curl หรือระบบไฟล์เลย) จึงตรวจความถูกต้องของ
     * อัลกอริทึมได้ครบโดยไม่ต้องมีบัญชี S3 จริง
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
            '',   // ไม่มี query string ในคำขอชุดนี้
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
     * ส่งคำขอที่เซ็นด้วย AWS Signature Version 4 แล้วคืนผลดิบ
     *
     * @param array<int,mixed> $curlOptions ตัวเลือก curl เพิ่มเติมเฉพาะคำสั่ง (ไฟล์อัปโหลด/ดาวน์โหลด)
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
            throw new ExecutionFailed('เริ่มการเชื่อมต่อ S3 ไม่สำเร็จ');
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
            // CURLOPT_FILE/CURLOPT_RETURNTRANSFER ทำให้ curl_exec คืน true ไม่ใช่เนื้อ body
            // เวลาที่ตัวรับเป็นไฟล์ — ไม่ใช่ความล้มเหลว
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

        // ข้อความ error ของ S3 เป็น XML: <Error><Code>...</Code><Message>...</Message></Error>
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

    /** ชื่อโฮสต์ของ endpoint ที่ตั้งไว้ หรือ endpoint มาตรฐานของ AWS ตาม region */
    private function defaultHost(): string
    {
        return $this->endpoint !== ''
            ? (string) (parse_url($this->endpoint, PHP_URL_HOST) ?? $this->endpoint)
            : "s3.{$this->region}.amazonaws.com";
    }

    /**
     * โฮสต์ที่ใช้ในคำขอจริง — virtual-hosted (`bucket.host`) เป็นค่าเริ่มต้นของ AWS เอง
     * ส่วน path-style (`host` เฉย ๆ + bucket อยู่ใน URI) จำเป็นสำหรับผู้ให้บริการที่ไม่มี
     * wildcard DNS ให้ทุก bucket เช่น MinIO ที่ตั้งเอง — เลือกได้ผ่าน config `path_style`
     */
    private function requestHost(): string
    {
        $host = $this->defaultHost();

        return $this->pathStyle ? $host : "{$this->bucket}.{$host}";
    }

    /** เข้ารหัสแต่ละส่วนของ key ตามกฎของ AWS SigV4 โดยคง `/` เป็นตัวคั่นไว้ */
    private function canonicalUri(string $key): string
    {
        $encodedKey = implode('/', array_map(rawurlencode(...), explode('/', $key)));

        return $this->pathStyle
            ? '/' . rawurlencode($this->bucket) . '/' . $encodedKey
            : '/' . $encodedKey;
    }
}
