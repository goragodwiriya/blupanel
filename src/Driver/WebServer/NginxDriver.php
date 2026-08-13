<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\Template;

/**
 * nginx — ทางเลือกที่สองของเว็บเซิร์ฟเวอร์ (ARCHITECTURE §10)
 *
 * โครงสร้างเหมือน ApacheDriver ทุกอย่างโดยเจตนา: ไฟล์ต่อหนึ่งเว็บไซต์ ชื่อขึ้นต้นด้วย
 * phpcp- ส่วนกลางของ config สร้างครั้งเดียวใช้ทั้งบล็อก HTTP และ HTTPS
 * และ configtest ต้องผ่านก่อน reload เสมอ — คนที่อ่านโค้ดสองไฟล์นี้ต่อกัน
 * ควรเห็นว่าเป็นเรื่องเดียวกันที่เขียนคนละภาษา ไม่ใช่สองระบบที่ต้องเรียนรู้แยกกัน
 *
 * ความต่างที่สำคัญจาก Apache:
 *   - ไม่มีระบบเปิด/ปิดโมดูลตอนรัน ทุกอย่างคอมไพล์มาแล้ว ensureModules() จึงคืน []
 *   - ไม่มี .htaccess เว็บที่พึ่ง .htaccess จะทำงานไม่เหมือนเดิม ต้องแปลงกฎเอง
 *   - ต้องกัน path info ปลอมของ fastcgi เอง (ดูคำอธิบายในเทมเพลต)
 */
final class NginxDriver implements WebServerDriver
{
    private const CONFIG_ROOT = '/etc/nginx';
    private const SITES_DIR = self::CONFIG_ROOT . '/conf.d';

    /** ไฟล์ระดับเครื่อง ไม่ใช่ของเว็บใดเว็บหนึ่ง — ชื่อเดียวกันทุกโหมด */
    public const LOCALHOST_FILE = self::SITES_DIR . '/phpcp-000-localhost.conf';

    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    /** เท่ากับค่าเริ่มต้นของเว็บทั่วไป — โฟลเดอร์พัฒนาไม่ได้ตั้งโควตาอัปโหลดไว้ */
    private const UPLOAD_LIMIT_MB = 64;

    public function __construct(
        private readonly Template $templates,
        /** null = ไม่เปิด http://localhost (ค่าเริ่มต้นของเครื่องที่ให้บริการจริง) */
        private readonly ?LocalhostSite $localhost = null,
    ) {
    }

    public function name(): string
    {
        return 'nginx';
    }

    public function unit(): string
    {
        return 'nginx';
    }

    public function runAsUser(): string
    {
        return 'www-data';
    }

    public function runAsGroup(): string
    {
        return 'www-data';
    }

    /**
     * nginx ผูกโมดูลไว้ตอนคอมไพล์ ไม่มีอะไรให้เปิดตอนรัน
     *
     * ถ้าเครื่องใช้ nginx ที่ไม่ได้คอมไพล์ ngx_http_ssl_module มา จะรู้ตอน configtest
     * ซึ่งเป็นความล้มเหลวที่ปลอดภัยเพราะ ConfigTransaction คืนไฟล์เดิมให้อยู่แล้ว
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        return [];
    }

    public function vhostPath(Site $site): string
    {
        // ชื่อไฟล์มาจากโดเมนที่ผ่าน Validator::domain() แล้ว จึงมีแต่ [a-z0-9.-]
        //
        // **vhost ของเว็บที่รับ wildcard ต้องถูกอ่านท้ายสุด** (PLAN-V2 เฟส E7) — nginx
        // อ่านไฟล์ตามลำดับตัวอักษร ถ้า `*.example.com` ถูกอ่านก่อน vhost ที่ระบุ
        // `blog.example.com` ไว้เต็ม ๆ คำขอของ blog จะตกไปที่เว็บ wildcard แทน
        // ซึ่งเป็นการรั่วข้ามเว็บไซต์ระหว่างลูกค้าคนละราย
        //
        // คำนำหน้า `zz-` ทำให้มันไปอยู่ท้ายเสมอโดยไม่ต้องพึ่งความบังเอิญของชื่อโดเมน
        // · `$site->domain` ยังเป็นชื่อจริงที่ผ่านการตรวจแล้ว ไม่มี `*` ปนมา
        return self::SITES_DIR . '/phpcp-' . ($site->hasWildcard() ? 'zz-wildcard-' : '') . $site->domain . '.conf';
    }

    /** @return list<string> */
    public function vhostPaths(Site $site): array
    {
        return [$this->vhostPath($site)];
    }

    /** @return array<string,string> */
    /**
     * ไม่แตะ `ports.conf` ของ Apache โดยตั้งใจ — โหมดนี้ไม่ได้ใช้ Apache เลย และ
     * nginx ถือพอร์ต 80 อยู่ · การเขียนคืนให้ Apache ฟัง 80 มีแต่จะทำให้ Apache
     * (ถ้ายังเปิดอยู่) สตาร์ตไม่ขึ้นเพราะพอร์ตชน
     *
     * @return array<string,string>
     */
    public function globalFiles(Executor $executor): array
    {
        if ($this->localhost === null) {
            return [];
        }

        return [
            self::LOCALHOST_FILE => $this->templates->render('nginx/localhost-standalone.conf.tpl', [
                'HTTP_PORT' => self::HTTP_PORT,
                'DOCROOT' => $executor->path($this->localhost->docroot),
                'FPM_SOCKET' => $executor->path($this->localhost->fpmSocket()),
                'ERROR_LOG' => $executor->path($this->localhost->errorLog()),
                'ACCESS_LOG' => $executor->path($this->localhost->accessLog()),
                'UPLOAD_LIMIT' => self::UPLOAD_LIMIT_MB
            ])
        ];
    }

    public function vhostFiles(Site $site, Executor $executor): array
    {
        return $this->globalFiles($executor) + [$this->vhostPath($site) => $this->renderVhost($site, $executor)];
    }

    public function renderVhost(Site $site, Executor $executor): string
    {
        // nginx ใส่ทุกโดเมนไว้ใน server_name บรรทัดเดียว ต่างจาก ServerAlias ของ Apache
        $aliases = new SafeBlock(
            $site->aliases === [] ? '' : ' ' . implode(' ', array_map(
                static fn (string $d): string => Template::assertValue('server_name', $d),
                $site->aliases,
            )),
        );

        if (!$site->isActive()) {
            return $this->templates->render('nginx/vhost-suspended.conf.tpl', [
                'DOMAIN' => $site->domain,
                'SERVER_ALIASES' => $aliases,
                'SUSPENDED_PAGE' => $executor->path($site->suspendedPage()),
                'ERROR_LOG' => $executor->path($site->errorLog()),
                'ACCESS_LOG' => $executor->path($site->accessLog()),
                'HTTP_PORT' => self::HTTP_PORT,
            ]);
        }

        $body = new SafeBlock($this->templates->render('nginx/vhost-body.conf.tpl', [
            // ไดเรกทอรีของผู้ดูแล — vhost อ่านเป็นอันสุดท้าย ค่าที่นั่นจึงชนะค่าเริ่มต้น
            'CUSTOM_DIR' => $executor->path(CustomConfig::siteDirectory('nginx', $site->domain)),
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
            'UPLOAD_LIMIT' => $site->uploadLimitMb,
        ]));

        $common = [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_BODY' => $body,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'HTTP_PORT' => self::HTTP_PORT,
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ];

        if ($site->sslMode === 'off') {
            return $this->templates->render('nginx/vhost.conf.tpl', $common);
        }

        $certificate = $this->certificatePath($site, $executor);

        return $this->templates->render('nginx/vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'DOCROOT' => $executor->path($site->docroot()),
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'บังคับ HTTPS' : 'เปิดใช้งาน',
            'HTTP_SECTION' => $this->httpSection($site, $body),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /** บล็อก HTTP ต่างกันตามโหมด — บังคับ HTTPS แล้วต้อง redirect ทุกอย่างที่ไม่ใช่ acme */
    private function httpSection(Site $site, SafeBlock $body): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $body;
        }

        // location ^~ ของ acme ด้านบนมีลำดับความสำคัญเหนือ location / นี้อยู่แล้ว
        // จึงไม่ต้องเขียนเงื่อนไขยกเว้นซ้ำเหมือนที่ต้องทำใน Apache
        return new SafeBlock(
            "\n    # บังคับ HTTPS — เส้นทางตรวจสอบของ Let's Encrypt ด้านบนถูกจับก่อนด้วย ^~\n"
            . "    location / {\n"
            . "        return 301 https://\$host\$request_uri;\n"
            . '    }',
        );
    }

    /**
     * HSTS ใส่เฉพาะตอนบังคับ HTTPS เท่านั้น — เหตุผลเดียวกับ ApacheDriver
     */
    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        return new SafeBlock(
            "\n    add_header Strict-Transport-Security \"max-age=15768000\" always;",
        );
    }

    /** ที่อยู่ของใบรับรองที่ใช้จริง — ใบของ Let's Encrypt มาก่อนใบที่เซ็นเอง */
    public function certificatePath(Site $site, Executor $executor): string
    {
        $selfSigned = CertbotManager::SELF_SIGNED_DIR . '/' . $site->domain;

        if (!$executor->exists($executor->path(CertbotManager::LIVE_DIR . '/' . $site->domain . '/fullchain.pem'))
            && $executor->exists($executor->path($selfSigned . '/fullchain.pem'))) {
            return $selfSigned;
        }

        return CertbotManager::LIVE_DIR . '/' . $site->domain;
    }

    /**
     * ตรวจ config ทั้งหมดด้วย nginx ตัวจริง
     *
     * เลือกคำสั่งตาม config tree ที่กำลังตรวจเหมือน ApacheDriver:
     * tree ของระบบใช้ `nginx -t` เปล่า ๆ ส่วน tree อื่น (sandbox) ต้องชี้ไฟล์หลักให้ชัด
     */
    public function testConfig(Executor $executor): array
    {
        $root = $executor->path(self::CONFIG_ROOT);

        $argv = $root === self::CONFIG_ROOT
            ? ['/usr/sbin/nginx', '-t']
            : ['/usr/sbin/nginx', '-t', '-p', $root, '-c', $root . '/nginx.conf'];

        $result = $executor->exec($argv, timeout: 20);

        // nginx -t เขียนผลลัพธ์ลง stderr แม้ตอนผ่าน ("syntax is ok")
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /** ดูรหัสออกด้วยเสมอ — reload ที่ล้มเงียบ ๆ ทำให้ค่าตั้งใหม่ไม่มีผลโดยไม่มีใครรู้ */
    public function reload(Executor $executor): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $this->unit()], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "เขียนค่าตั้งเรียบร้อยแล้วแต่สั่ง reload %s ไม่สำเร็จ — ค่าตั้งใหม่จะยังไม่มีผลจนกว่าจะ reload สำเร็จ\n\n%s",
                $this->unit(),
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->exists($executor->path(self::CONFIG_ROOT));
    }

    /** ไดเรกทอรีที่เก็บ vhost — ใช้ตอนสร้างโครงสร้างครั้งแรก */
    public static function sitesDir(): string
    {
        return self::SITES_DIR;
    }

    public static function configRoot(): string
    {
        return self::CONFIG_ROOT;
    }
}
