<?php

declare(strict_types=1);

/**
 * เครื่องมือยิงคำขอเข้า HttpKernel จริงในโปรเซสเดียว — ไม่ต้องเปิดเว็บเซิร์ฟเวอร์
 *
 * ทำไมต้องเป็นแบบนี้แทนการ curl ใส่เซิร์ฟเวอร์ทดสอบ:
 *   - เทสต์ contract ต้องรันได้ใน CI ที่ไม่มี root และไม่มีพอร์ตให้ผูก
 *   - คำขอเดินผ่าน middleware ครบทั้งเจ็ดตัวจริง ๆ ไม่ใช่แค่เรียก controller ตรง ๆ
 *     ซึ่งแปลว่าเทสต์ครอบคลุมสิ่งที่แผนสั่งไว้จริง: "ห้ามมี HTML หลุดออกมาแม้แต่ไบต์เดียว
 *     แม้ในกรณี error ทุกชนิด" — error ส่วนใหญ่เกิดที่ middleware ไม่ใช่ที่ controller
 *
 * ทุกชุดทดสอบใช้ฐานข้อมูลและ config ของตัวเองใน temp dir จึงไม่แตะระบบของนักพัฒนาเลย
 */

use Phpcp\Kernel\App;
use Phpcp\Kernel\HttpKernel;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Csrf;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;

final class ApiHarness
{
    public App $app;

    private HttpKernel $kernel;

    /** @var array<string,string> คุกกี้ที่สะสมจากคำตอบก่อนหน้า เหมือนเบราว์เซอร์ */
    private array $cookies = [];

    private string $csrfToken = '';

    private function __construct(public readonly string $root)
    {
        $this->app = App::boot($root);
        $this->kernel = new HttpKernel($this->app);
    }

    /**
     * สร้างสภาพแวดล้อมใหม่ทั้งชุด: config + ฐานข้อมูลที่ migrate แล้ว
     *
     * ใช้ `PHPCP_CONFIG` ชี้ไฟล์ config เพราะ Config::locate() ให้ตัวแปรนี้มาก่อน
     * /etc/phpcp/config.php เสมอ — ถ้าไม่ทำ เทสต์บนเครื่องที่ติดตั้ง panel จริงไว้แล้ว
     * จะไปอ่าน config ของระบบจริงและเขียนทับฐานข้อมูลของเครื่องนั้น
     */
    public static function boot(): self
    {
        $root = sys_get_temp_dir() . '/phpcp-api-' . getmypid() . '-' . bin2hex(random_bytes(4));

        mkdir($root . '/etc', 0700, true);

        // migrations/templates/views/public ใช้ของจริง ไม่ต้องสำเนา
        //
        // `public` อยู่ในรายการเพราะ `SpaController` อ่าน `public/assets/spa/index.html` จาก root
        // ของ config — ถ้าไม่ผูกไว้ เทสต์ของ shell จะได้ 500 ทั้งที่ไฟล์มีอยู่จริงในโปรเจกต์
        foreach (['db', 'templates', 'views', 'public'] as $shared) {
            @symlink(PHPCP_ROOT . '/' . $shared, $root . '/' . $shared);
        }

        file_put_contents($root . '/etc/config.php', sprintf(
            "<?php return %s;\n",
            var_export([
                'mode' => 'sandbox',
                'layout' => 'portable',
                'panel' => ['cookie_secure' => false],
                'security' => ['secret_key' => Secret::generateKey(), 'password_min_length' => 12],
                'log' => ['level' => 'error'],
                'sandbox' => ['prefix' => $root . '/sandbox'],
            ], true),
        ));

        putenv('PHPCP_CONFIG=' . $root . '/etc/config.php');

        register_shutdown_function(static function () use ($root): void {
            self::removeTree($root);
            putenv('PHPCP_CONFIG');
        });

        $harness = new self($root);
        $harness->app->config->paths->ensureDirectories();
        $harness->app->db()->migrate($harness->app->config->paths->migrations());

        return $harness;
    }

    /** สร้างผู้ใช้จริงในฐานข้อมูลของชุดทดสอบนี้ */
    public function createUser(
        string $username,
        string $password,
        string $role = Permissions::SUPERADMIN,
        bool $mustChangePassword = false,
    ): int {
        $users = new \Phpcp\Domain\UserRepository($this->app->db());

        return $users->create($username, $password, $role, $username, $mustChangePassword);
    }

    /**
     * ผู้ใช้ที่มีบัญชีระบบพร้อมแล้ว — ใช้เมื่อเทสต์ต้องแทรกแถว sites เข้าฐานข้อมูลตรง ๆ
     *
     * ของจริง `site.create` เป็นคนตั้ง `users.system_user` ให้ตอน provision เว็บแรก
     * เทสต์ที่ข้ามขั้นตอนนั้นจึงต้องตั้งเอง ไม่งั้นเว็บจะถือว่ายัง provision ไม่เสร็จ
     * และหายไปจากตัวจัดการไฟล์ตามที่ตั้งใจไว้
     */
    public function createHostingUser(
        string $username,
        string $password,
        string $role = Permissions::WEBADMIN,
    ): int {
        $id = $this->createUser($username, $password, $role);

        $this->app->db()->update('users', ['system_user' => $username], ['id' => $id]);

        return $id;
    }

    /**
     * ยิงคำขอหนึ่งครั้งผ่าน pipeline เต็ม
     *
     * @param array<string,mixed>  $body    ส่งเป็น JSON เหมือนที่ SPA ทำจริง
     * @param array<string,string> $headers header เพิ่มเติม
     * @param array<string,mixed>  $query
     */
    public function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        array $query = [],
        bool $withCsrf = true,
        /** ส่ง body ดิบเองได้ ใช้ทดสอบกรณี JSON พัง */
        ?string $rawBody = null,
    ): ApiResponse {
        $raw = $rawBody ?? ($body === [] ? null : json_encode($body, JSON_UNESCAPED_UNICODE));

        if ($raw !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($withCsrf && $this->csrfToken !== '') {
            $headers[Csrf::HEADER] = $this->csrfToken;
        }

        $response = $this->kernel->handle(Request::make(
            method: $method,
            path: $path,
            query: $query,
            headers: $headers,
            cookies: $this->cookies,
            rawBody: $raw,
        ));

        $this->rememberCookies($response);

        $result = new ApiResponse($response);

        // เก็บ token ล่าสุดไว้ใช้กับคำขอถัดไป เหมือนที่ HttpClient ของ Now.js ทำ
        $fromHeader = $response->headers()[Csrf::HEADER] ?? '';
        if ($fromHeader !== '') {
            $this->csrfToken = $fromHeader;
        } elseif (is_string($result->json['data']['csrf_token'] ?? null)) {
            $this->csrfToken = $result->json['data']['csrf_token'];
        }

        return $result;
    }

    /** ทิ้งคุกกี้และ token ทั้งหมด เหมือนเปิดเบราว์เซอร์ใหม่ */
    public function forget(): void
    {
        $this->cookies = [];
        $this->csrfToken = '';
    }

    /**
     * ล้างโควตา rate limit ของทุก bucket
     *
     * จำเป็นเพราะเทสต์ชุดนี้ล็อกอินหลายสิบครั้งจาก IP เดียวกัน ซึ่งของจริงจะโดน
     * ตัดที่ครั้งที่ 6 (โควตาหน้าล็อกอินคือ 5 ครั้งรัว) — นั่นคือพฤติกรรมที่ถูกต้อง
     * และมีเทสต์ของตัวเองแยกไว้ · เคสอื่นที่ไม่ได้ทดสอบเรื่องนี้จึงต้องเริ่มจากโควตาเต็ม
     * ไม่งั้นผลของเคสหนึ่งจะไปทำให้เคสถัดไปล้มโดยไม่เกี่ยวกับสิ่งที่มันตรวจเลย
     */
    public function clearRateLimits(): void
    {
        $this->app->db()->run('DELETE FROM rate_limits');
    }

    public function csrfToken(): string
    {
        return $this->csrfToken;
    }

    /**
     * อ่านคุกกี้จากคำตอบ — Response เก็บไว้ในรูปแบบของตัวเองเพราะยังไม่ได้ส่งออกจริง
     */
    private function rememberCookies(Response $response): void
    {
        $reflection = new ReflectionProperty(Response::class, 'cookies');

        foreach ($reflection->getValue($response) as $cookie) {
            if ($cookie['value'] === '') {
                unset($this->cookies[$cookie['name']]);

                continue;
            }

            $this->cookies[$cookie['name']] = $cookie['value'];
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}

/** คำตอบหนึ่งครั้งในรูปที่เทสต์อ่านง่าย */
final class ApiResponse
{
    public readonly int $status;

    public readonly string $body;

    /** @var array<string,string> */
    public readonly array $headers;

    /** @var array<string,mixed> */
    public readonly array $json;

    public function __construct(Response $response)
    {
        $this->status = $response->status();
        $this->body = $response->body();
        $this->headers = $response->headers();

        $decoded = $this->body === '' ? [] : json_decode($this->body, true);
        $this->json = is_array($decoded) ? $decoded : [];
    }

    public function contentType(): string
    {
        return $this->headers['Content-Type'] ?? '';
    }

    public function errorCode(): string
    {
        return (string) ($this->json['error']['code'] ?? '');
    }

    /**
     * ค่าจากคำตอบ — มองทั้งชั้น `data` และระดับบนสุด
     *
     * คำตอบมีสองแบบตามกฎ "คำตอบหนึ่งทำหน้าที่เดียว" ที่ Now.js บังคับไว้:
     * แบบ**อ่าน**มี `data` ให้ผูกกับหน้าจอ · แบบ**สั่งงาน**ไม่มี `data` เลย ค่าที่
     * ผู้เรียกต้องใช้อยู่ระดับบนสุดคู่กับ `message` และ `actions`
     *
     * เทสต์ไม่ควรต้องรู้ว่า endpoint ไหนเป็นแบบไหนเพื่อจะอ่านค่าหนึ่งค่า
     */
    public function data(string $key, mixed $default = null): mixed
    {
        return $this->json['data'][$key] ?? $this->json[$key] ?? $default;
    }

    /** คำสั่งหน้าจอที่คำตอบสั่งมา — ชนิดตามลำดับ */
    public function actionTypes(): array
    {
        return array_column($this->json['actions'] ?? [], 'type');
    }

    public function isJson(): bool
    {
        return str_starts_with($this->contentType(), 'application/json');
    }

    /** true = มีร่องรอย HTML ปนอยู่ในคำตอบ ซึ่งสัญญาของ v2 ห้ามเด็ดขาด */
    public function looksLikeHtml(): bool
    {
        $body = ltrim($this->body);

        return $body !== '' && (str_starts_with($body, '<') || str_contains($body, '<!doctype'));
    }
}
