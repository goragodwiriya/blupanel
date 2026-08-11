<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * คำขอหนึ่งครั้ง — ห่อ superglobal ไว้ที่เดียว
 *
 * โค้ดส่วนอื่นห้ามแตะ $_GET/$_POST/$_SERVER โดยตรง เพื่อให้มีจุดเดียวที่ควบคุม
 * การหาค่า IP จริงและการอ่านค่าที่ไม่น่าเชื่อถือ
 */
final class Request
{
    /** @param array<string,string> $params พารามิเตอร์จาก route เช่น {id} */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $cookies,
        public readonly array $server,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $requestId,
        public array $params = [],
        /** body ดิบที่ฉีดเข้ามาได้ตอนทดสอบ — ปกติเป็น null แล้วอ่านจาก php://input */
        private ?string $rawBody = null,
    ) {
    }

    /** @var array<string,mixed>|null ผลการแปลง JSON body เก็บไว้ใช้ซ้ำ */
    private ?array $json = null;

    /**
     * สร้าง Request จากค่าที่กำหนดเอง — ใช้ในเทสต์ contract ของ REST API
     *
     * มีทางนี้เพื่อให้ทดสอบทั้ง pipeline (middleware + routing + controller) ได้ในโปรเซสเดียว
     * โดยไม่ต้องเปิดเว็บเซิร์ฟเวอร์จริง ซึ่งทำให้เทสต์ contract รันใน CI ที่ไม่มี root ได้
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $headers ชื่อ header ตามที่เขียนจริง เช่น 'X-CSRF-Token'
     * @param array<string,string> $cookies
     */
    public static function make(
        string $method,
        string $path,
        array $query = [],
        array $post = [],
        array $headers = [],
        array $cookies = [],
        ?string $rawBody = null,
        string $ip = '127.0.0.1',
    ): self {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => $ip];

        // วาง header ลง $_SERVER แบบเดียวกับที่ PHP-FPM ทำจริง — Content-Type และ
        // Content-Length ไม่มีคำนำหน้า HTTP_ ตาม RFC 3875 · ถ้าเทสต์วางแบบอื่น
        // มันจะเดินคนละเส้นทางกับของจริงแล้วบั๊กแบบที่เคยเกิดจะหลุดผ่านไปได้อีก
        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', $name));
            $server[in_array($normalized, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
                ? $normalized
                : 'HTTP_'.$normalized] = $value;
        }

        return new self(
            method: strtoupper($method),
            path: $path,
            query: $query,
            post: $post,
            files: [],
            cookies: $cookies,
            server: $server,
            ip: $ip,
            userAgent: $headers['User-Agent'] ?? 'phpcp-test',
            requestId: bin2hex(random_bytes(8)),
            rawBody: $rawBody,
        );
    }

    /**
     * @param Config $config
     */
    public static function capture(Config $config): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/'.trim($path, '/');

        return new self(
            method: $method,
            path: $path === '//' ? '/' : $path,
            query: $_GET,
            post: $_POST,
            files: $_FILES,
            cookies: $_COOKIE,
            server: $server,
            ip: self::resolveIp($server, $config->list('panel.trusted_proxies')),
            userAgent: substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 512),
            requestId: bin2hex(random_bytes(8)),
        );
    }

    /**
     * หา IP จริงของผู้ใช้
     *
     * X-Forwarded-For เชื่อถือได้ก็ต่อเมื่อคำขอมาจาก proxy ที่เราตั้งไว้เองเท่านั้น
     * ไม่อย่างนั้นผู้โจมตีปลอม header นี้เพื่อหลบ rate limit และ IP allowlist ได้ทันที
     *
     * @param array<string,mixed> $server
     * @param list<string> $trustedProxies
     */
    private static function resolveIp(array $server, array $trustedProxies): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded === '') {
            return $remote;
        }

        // เอาตัวซ้ายสุดที่เป็น IP ถูกต้อง = ผู้ใช้จริงที่อยู่ปลายสุด
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    /**
     * @param array $params
     * @return mixed
     */
    /**
     * พารามิเตอร์จากเส้นทางเป็นสตริงเสมอ — ที่นี่บังคับให้เป็นสตริงด้วย
     *
     * เพราะโค้ดที่เรียกใช้เองมักส่งตัวเลขมา (`withParams(['id' => $id])` ตอนที่
     * store() ส่งงานต่อให้ update()) แล้ว param()/paramInt() ที่คาดว่าจะได้สตริง
     * ก็ระเบิดเป็น TypeError กลางคำขอ
     */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $params,
        );

        return $clone;
    }

    /**
     * @param string $key
     * @param string $default
     * @return mixed
     */
    public function param(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param int $default
     * @return mixed
     */
    public function paramInt(string $key, int $default = 0): int
    {
        $value = $this->params[$key] ?? null;

        if (!is_string($value)) {
            return is_int($value) ? $value : $default;
        }

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * @return mixed
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function get(string $key, string $default = ''): string
    {
        $value = $this->lookup($this->query, $key);

        return $value !== null && is_scalar($value) ? (string) $value : $default;
    }

    /** ค่าจาก query string ที่ต้องเป็นตัวเลข — ใช้กับ page/per_page ของ REST API */
    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /**
     * เนื้อหา JSON ที่ส่งมาใน body — คืน array ว่างถ้าไม่ใช่ JSON
     *
     * จำเป็นสำหรับ REST API เพราะ PHP เติม `$_POST` ให้เฉพาะ form-encoded เท่านั้น
     * คำขอ `PATCH`/`PUT` ที่ส่ง `application/json` มาจะได้ `$_POST` ว่างเปล่าเสมอ
     *
     * อ่าน `php://input` ครั้งเดียวแล้วจำไว้ — สตรีมนี้อ่านซ้ำไม่ได้ใน SAPI บางตัว
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        if (!str_contains(strtolower($this->header('Content-Type')), 'application/json')) {
            return $this->json = [];
        }

        $raw = $this->rawBody ?? (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        return $this->json = is_array($decoded) ? $decoded : [];
    }

    /** true = ส่ง Content-Type: application/json มาแต่เนื้อหาแปลงไม่ได้ (400 BAD_REQUEST) */
    public function hasBrokenJson(): bool
    {
        if (!str_contains(strtolower($this->header('Content-Type')), 'application/json')) {
            return false;
        }

        $raw = $this->rawBody ?? (string) file_get_contents('php://input');

        return trim($raw) !== '' && !is_array(json_decode($raw, true));
    }

    /**
     * ค่าที่ผู้เรียกส่งมา ไม่ว่าจะมาทาง JSON body หรือฟอร์ม
     *
     * REST API ใช้เมธอดนี้แทน input() เพื่อให้รองรับทั้ง SPA (JSON) และการทดสอบด้วย
     * `curl -d` (form-encoded) โดยไม่ต้องเขียนสองทาง — ข้อกำหนดในเกณฑ์รับงานเฟส B
     * คือ "เรียกได้ครบทุกทรัพยากรด้วย curl โดยไม่ต้องเปิดเบราว์เซอร์เลย"
     */
    public function payload(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key);

        return $value ?? $default;
    }

    public function payloadString(string $key, string $default = ''): string
    {
        $value = $this->payload($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** เส้นทางนี้อยู่ใต้ REST API v2 หรือไม่ — ใช้ตัดสินรูปแบบคำตอบใน middleware */
    public function isApiV2(): bool
    {
        return $this->path === '/api/v2' || str_starts_with($this->path, '/api/v2/');
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key);

        return $value !== null && is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key, (string) $default);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $value = $this->lookup($this->post, $key) ?? $this->lookup($this->query, $key) ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($value, is_scalar(...))));
    }

    /**
     * อ่านค่าจาก $_GET/$_POST โดยรองรับคีย์ที่มีจุด
     *
     * PHP แปลง `.` และช่องว่างในชื่อพารามิเตอร์จากฟอร์มเป็น `_` ก่อนใส่ superglobal
     * ดังนั้น name="notify.telegram.enabled" จะกลายเป็นคีย์ notify_telegram_enabled
     * — ถ้าหาด้วยคีย์จุดไม่เจอ ให้ลองคีย์ที่ PHP แปลงแล้ว
     *
     * @param array<string,mixed> $bag
     */
    private function lookup(array $bag, string $key): mixed
    {
        if (array_key_exists($key, $bag)) {
            return $bag[$key];
        }

        if (strpbrk($key, '. ') === false) {
            return null;
        }

        $alt = str_replace(['.', ' '], '_', $key);

        return array_key_exists($alt, $bag) ? $bag[$alt] : null;
    }

    /**
     * ไฟล์ที่อัปโหลดมาในช่องหนึ่ง ๆ — คืนรายการเสมอ ไม่ว่าจะส่งมาไฟล์เดียวหรือหลายไฟล์
     *
     * PHP จัดรูป $_FILES ต่างกันสองแบบ: ช่องเดี่ยวได้ค่าเป็นสเกลาร์ ส่วนช่องที่ชื่อ
     * ลงท้ายด้วย [] ได้ค่าเป็นอาร์เรย์ขนานกัน การรวมสองรูปนี้ไว้ที่เดียวทำให้
     * controller ไม่ต้องเขียนสองทางและพลาดทางใดทางหนึ่ง
     *
     * @return list<array{name:string,tmp_name:string,size:int,error:int}>
     */
    public function files(string $key): array
    {
        $entry = $this->files[$key] ?? null;
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }

        $names = is_array($entry['name']) ? $entry['name'] : [$entry['name']];
        $result = [];

        foreach (array_keys($names) as $index) {
            $pick = static fn(string $field): mixed => is_array($entry[$field] ?? null)
                ? ($entry[$field][$index] ?? null)
                : ($entry[$field] ?? null);

            $result[] = [
                // ชื่อจากเบราว์เซอร์ไม่น่าเชื่อถือ ผู้เรียกต้องตรวจก่อนใช้เสมอ
                'name' => (string) $pick('name'),
                'tmp_name' => (string) $pick('tmp_name'),
                'size' => (int) $pick('size'),
                'error' => (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE)
            ];
        }

        return $result;
    }

    /**
     * @param string $name
     * @param string $default
     */
    public function cookie(string $name, string $default = ''): string
    {
        $value = $this->cookies[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param string $name
     * @param string $default
     */
    /**
     * header สองตัวที่ CGI ไม่ใส่คำนำหน้า `HTTP_` ให้
     *
     * ตาม RFC 3875 (CGI) `Content-Type` และ `Content-Length` ถูกส่งต่อเป็นตัวแปร
     * `CONTENT_TYPE` / `CONTENT_LENGTH` เฉย ๆ ไม่ใช่ `HTTP_CONTENT_TYPE` เหมือน header อื่น
     * — PHP-FPM, mod_cgi และ FrankenPHP ทำตามนี้ทั้งหมด
     *
     * @var array<string,string>
     */
    private const CGI_HEADERS = [
        'CONTENT_TYPE' => 'CONTENT_TYPE',
        'CONTENT_LENGTH' => 'CONTENT_LENGTH',
    ];

    /**
     * ค่า header ของคำขอ
     *
     * **เคยเป็นบั๊กที่ทำให้ REST API v2 ทั้งชุดใช้งานไม่ได้บนเซิร์ฟเวอร์จริง:** เมธอดนี้
     * มองหา `HTTP_CONTENT_TYPE` อย่างเดียว ซึ่ง PHP-FPM ไม่เคยตั้งให้ · ผลคือ `json()`
     * เห็น Content-Type เป็นค่าว่างแล้วไม่แปลง body ทุกคำขอที่ส่ง JSON มาจึงได้ payload
     * ว่างเปล่าแบบเงียบ ๆ — ล็อกอินผ่าน API ไม่ได้ สร้างเว็บไม่ได้ ทั้งที่ไม่มี error ใด ๆ
     *
     * เทสต์ contract 71 เคสผ่านหมดเพราะ `make()` เซ็ต `HTTP_CONTENT_TYPE` ให้เอง
     * ตอนนี้ `make()` เซ็ตแบบเดียวกับ CGI แล้ว เทสต์จึงเดินเส้นทางเดียวกับของจริง
     */
    public function header(string $name, string $default = ''): string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        $key = self::CGI_HEADERS[$normalized] ?? 'HTTP_'.$normalized;

        $value = $this->server[$key] ?? $this->server['HTTP_'.$normalized] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** true = ฝั่ง client ต้องการ JSON (เรียกจาก fetch ใน JS) */
    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept'), 'application/json')
        || $this->header('X-Requested-With') === 'fetch'
        || str_starts_with($this->path, '/api/');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
        || (int) ($this->server['SERVER_PORT'] ?? 0) === 443
        || $this->header('X-Forwarded-Proto') === 'https';
    }
}
