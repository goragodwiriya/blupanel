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
 * Apache 2.4 — ตรงกับเว็บเซิร์ฟเวอร์บนเครื่องเป้าหมาย
 *
 * ไฟล์ vhost ถูกเขียนลง /etc/apache2/sites-enabled/ ชื่อขึ้นต้นด้วย phpcp- ทุกไฟล์
 * เพื่อให้แยกออกชัดเจนว่าไฟล์ไหน panel เป็นคนสร้าง และไม่ไปทับ vhost ที่ผู้ดูแล
 * เขียนเองไว้ก่อนหน้า
 */
final class ApacheDriver implements WebServerDriver
{
    private const CONFIG_ROOT = '/etc/apache2';
    private const SITES_DIR = self::CONFIG_ROOT . '/sites-enabled';
    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    public function __construct(private readonly Template $templates)
    {
    }

    public function name(): string
    {
        return 'apache';
    }

    public function unit(): string
    {
        return 'apache2';
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
     * โมดูลที่ไฟล์ vhost ซึ่งระบบสร้างขึ้น "ต้อง" มี ไม่อย่างนั้น config ไม่ผ่านเลย
     *
     * ตั้งใจไม่ห่อ directive เหล่านี้ด้วย <IfModule> ทั้งที่ทำได้ง่ายกว่า —
     * เพราะ <IfModule> จะข้าม directive ที่หายไปแบบเงียบ ๆ ซึ่งกับ HSTS
     * แปลว่าเว็บที่ตั้งค่า "บังคับ HTTPS" ไว้จะไม่ได้ HSTS จริงโดยไม่มีใครรู้
     * ปล่อยให้ configtest ล้มแล้ว ConfigTransaction คืนค่าเดิม เป็นความล้มเหลวที่ปลอดภัยกว่า
     */
    private const REQUIRED_MODULES = ['headers', 'rewrite', 'proxy', 'proxy_fcgi'];

    /** เพิ่มเมื่อเว็บไซต์ใดเว็บไซต์หนึ่งเปิด HTTPS */
    private const SSL_MODULES = ['ssl'];

    /**
     * เปิดโมดูลที่จำเป็นถ้ายังไม่ได้เปิด
     *
     * panel เป็นเจ้าของ Apache stack นี้อยู่แล้ว การเปิดโมดูลที่ config ของตัวเองต้องใช้
     * จึงไม่ใช่การไปยุ่งกับของผู้ดูแล แต่คือการทำให้สิ่งที่ตัวเองเขียนออกไปใช้งานได้จริง
     *
     * @return list<string> โมดูลที่เพิ่งถูกเปิดในรอบนี้
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        $needed = $withSsl ? [...self::REQUIRED_MODULES, ...self::SSL_MODULES] : self::REQUIRED_MODULES;
        $enabledDir = $executor->path(self::CONFIG_ROOT . '/mods-enabled');
        $enabled = [];

        foreach ($needed as $module) {
            if ($executor->exists($enabledDir . '/' . $module . '.load')) {
                continue;
            }

            // a2enmod มีเฉพาะบน Debian/Ubuntu ซึ่งเป็นระบบที่ v1 รองรับ
            $result = $executor->exec([$executor->path('/usr/sbin/a2enmod'), '-q', $module], timeout: 30);

            if ($result->ok()) {
                $enabled[] = $module;
            }
        }

        return $enabled;
    }

    public function vhostPath(Site $site): string
    {
        // ชื่อไฟล์มาจากโดเมนที่ผ่าน Validator::domain() แล้ว จึงมีแต่ [a-z0-9.-]
        //
        // **vhost ของเว็บที่รับ wildcard ต้องถูกอ่านท้ายสุด** (PLAN-V2 เฟส E7) — Apache
        // อ่านไฟล์ตามลำดับตัวอักษร ถ้า `*.example.com` ถูกอ่านก่อน vhost ที่ระบุ
        // `blog.example.com` ไว้เต็ม ๆ คำขอของ blog จะตกไปที่เว็บ wildcard แทน
        // ซึ่งเป็นการรั่วข้ามเว็บไซต์ระหว่างลูกค้าคนละราย
        //
        // คำนำหน้า `zz-` ทำให้มันไปอยู่ท้ายเสมอโดยไม่ต้องพึ่งความบังเอิญของชื่อโดเมน
        // · `$site->domain` ยังเป็นชื่อจริงที่ผ่านการตรวจแล้ว ไม่มี `*` ปนมา
        return self::SITES_DIR . '/phpcp-' . ($site->hasWildcard() ? 'zz-wildcard-' : '') . $site->domain . '.conf';
    }

    public function renderVhost(Site $site, Executor $executor): string
    {
        $aliases = Template::lines('ServerAlias', $site->aliases);

        if (!$site->isActive()) {
            return $this->templates->render('apache/vhost-suspended.conf.tpl', [
                'DOMAIN' => $site->domain,
                'SERVER_ALIASES' => $aliases,
                'DOCROOT' => $executor->path($site->docroot()),
                'SUSPENDED_PAGE' => $executor->path($site->suspendedPage()),
                'ERROR_LOG' => $executor->path($site->errorLog()),
                'ACCESS_LOG' => $executor->path($site->accessLog()),
                'HTTP_PORT' => self::HTTP_PORT,
            ]);
        }

        // ส่วนกลางของ vhost ถูกสร้างครั้งเดียวแล้วใช้ทั้งบล็อก :80 และ :443
        // ถ้าปล่อยให้เทมเพลต SSL คัดลอกกฎเหล่านี้ไปเอง วันหนึ่งกฎกันไฟล์ .env หรือ .git
        // จะถูกแก้ที่เดียวแล้วลืมอีกที่ กลายเป็นช่องโหว่ที่เปิดเฉพาะบน HTTPS
        $body = new SafeBlock($this->templates->render('apache/vhost-body.conf.tpl', [
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
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
            return $this->templates->render('apache/vhost.conf.tpl', $common);
        }

        $certificate = $this->certificatePath($site, $executor);

        return $this->templates->render('apache/vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'DOCROOT' => $executor->path($site->docroot()),
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'บังคับ HTTPS' : 'เปิดใช้งาน',
            'HTTP_SECTION' => $this->httpSection($site, $body),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /**
     * บล็อก :80 ต่างกันตามโหมด — บังคับ HTTPS แล้วต้อง redirect ทุกอย่างที่ไม่ใช่ acme
     */
    private function httpSection(Site $site, SafeBlock $body): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $body;
        }

        return new SafeBlock(
            "\n    # บังคับ HTTPS — ยกเว้นเส้นทางตรวจสอบของ Let's Encrypt ด้านบน\n"
            . "    RewriteEngine On\n"
            . "    RewriteCond %{REQUEST_URI} !^/\\.well-known/acme-challenge/\n"
            . '    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]',
        );
    }

    /**
     * HSTS ใส่เฉพาะตอนบังคับ HTTPS เท่านั้น
     *
     * เบราว์เซอร์จำ header นี้ไว้แล้วปฏิเสธ HTTP ของโดเมนนั้นไปเลยตามระยะที่กำหนด
     * ถ้าใส่ตอนที่ยังเปิดทั้งสองทาง แล้วผู้ดูแลเปลี่ยนใจปิด SSL ทีหลัง
     * ผู้เข้าเว็บที่เคยเข้ามาแล้วจะเข้าเว็บไม่ได้เลยจนกว่าจะหมดอายุ — แก้ที่เซิร์ฟเวอร์ไม่ได้
     */
    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        // 6 เดือน ไม่ใส่ preload เพราะ preload ถอนออกยากและต้องสมัครกับรายชื่อกลาง
        return new SafeBlock(
            "\n    Header always set Strict-Transport-Security \"max-age=15768000\"",
        );
    }

    /**
     * ที่อยู่ของใบรับรองที่ใช้จริง — ใบของ Let's Encrypt มาก่อนใบที่เซ็นเอง
     *
     * ถ้าไม่มีทั้งคู่ยังคืนเส้นทางของ Let's Encrypt ไป แล้วปล่อยให้ `apache2 -t`
     * เป็นคนบอกว่าไฟล์ไม่มีอยู่ — ดีกว่าเขียน config ที่ชี้ไปที่อื่นแบบเงียบ ๆ
     * เพราะ ConfigTransaction จะ rollback ให้เองเมื่อ configtest ไม่ผ่าน
     */
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
     * ตรวจ config ทั้งหมด — จุดที่กันไม่ให้ vhost พังทำให้เว็บทั้งเครื่องดับ
     *
     * เลือกคำสั่งตาม "config tree ที่กำลังตรวจ" ไม่ใช่ตามโหมดการทำงาน:
     *   - tree ของระบบ  → apache2ctl -t ซึ่ง source /etc/apache2/envvars ให้ก่อน
     *     (จำเป็นบน Debian/Ubuntu เพราะ apache2.conf อ้าง ${APACHE_LOG_DIR})
     *   - tree อื่น (sandbox) → apache2 -d <root> -f <root>/apache2.conf -t
     *     ซึ่งใช้ config ที่เขียนครบในตัวเองอยู่แล้ว ไม่ต้องมี envvars
     *
     * ทั้งสองทางเป็นการรัน apache ตัวจริง — ส่วนที่บั๊กบ่อยที่สุดจึงถูกตรวจด้วยของจริง
     * แม้อยู่ในโหมดทดสอบ (ARCHITECTURE §6.3)
     */
    public function testConfig(Executor $executor): array
    {
        $root = $executor->path(self::CONFIG_ROOT);

        $argv = $root === self::CONFIG_ROOT
            ? ['/usr/sbin/apache2ctl', '-t']
            : ['/usr/sbin/apache2', '-d', $root, '-f', $root . '/apache2.conf', '-t'];

        $result = $executor->exec($argv, timeout: 20);

        // apache2 -t เขียนผลลัพธ์ลง stderr แม้ตอนผ่าน ("Syntax OK")
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
