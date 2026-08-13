<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Template;

/**
 * nginx ชั้นหน้า + Apache ชั้นหลัง — โหมดเดียวที่ `.htaccess` ใช้งานได้จริงบน nginx
 *
 * **ทำไมถึงมีโหมดนี้:** nginx ไม่รองรับ `.htaccess` และจะไม่รองรับ — มันอ่าน config
 * ครั้งเดียวตอน start ไม่มีกลไก config รายไดเรกทอรี · เว็บที่ย้ายมาจากโฮสต์อื่นเกือบ
 * ทั้งหมดพึ่ง `.htaccess` (WordPress, Laravel, กฎกันโฟลเดอร์, redirect ที่ลูกค้าตั้งเอง)
 * การแปลงกฎเหล่านั้นเป็น config ของ nginx อัตโนมัติแปลว่าต้องเอาข้อความที่ลูกค้าเขียน
 * ไปประกอบเป็นไฟล์ที่ root โหลด ซึ่งเป็นทางยกระดับสิทธิ์ที่กว้างเกินกว่าจะยอมรับได้
 *
 * โหมดนี้แก้ด้วยการไม่แปลงอะไรเลย: ให้ Apache ที่อ่าน `.htaccess` เป็นของมันอยู่แล้ว
 * เป็นคนตอบทุกคำขอ ส่วน nginx ทำหน้าที่ที่มันเก่งกว่า — จบ TLS, รับผู้ใช้ที่เน็ตช้า
 * แทน Apache, จำกัดขนาด body, เป็นจุดเดียวที่คุม rate limit
 *
 * ```
 *   ผู้ใช้ ──► nginx :80/:443 ──► Apache 127.0.0.1:8080 ──► PHP-FPM (uid ของลูกค้า)
 *             จบ TLS ที่นี่        อ่าน .htaccess ที่นี่
 * ```
 *
 * **สิ่งที่ต่างจากโหมด apache ล้วน และต้องระวัง:**
 *
 *   1. Apache ไม่ฟัง 80/443 อีกต่อไป — `backend-ports.conf.tpl` เขียนทับ
 *      `/etc/apache2/ports.conf` ให้ฟังเฉพาะ loopback · ไฟล์เดิมถูกสำรองโดย
 *      ConfigTransaction เหมือนไฟล์อื่นทุกไฟล์
 *   2. ที่อยู่ผู้ใช้จริงมาถึง Apache ผ่าน `X-Forwarded-For` + `mod_remoteip` เท่านั้น
 *      ถ้าโมดูลนี้หายไป log จะเป็น 127.0.0.1 ทั้งหมดแล้ว fail2ban จะแบนไม่ได้เลย
 *      จึงอยู่ใน REQUIRED_MODULES ไม่ใช่ห่อด้วย IfModule
 *   3. การบังคับ HTTPS ทำที่ **ชั้นหน้าเท่านั้น** — ถ้าชั้นหลัง redirect ด้วยจะวนไม่จบ
 *      เพราะคำขอที่ nginx ส่งมาเป็น http เสมอ
 */
final class NginxProxyDriver implements WebServerDriver
{
    private const NGINX_ROOT = '/etc/nginx';
    private const NGINX_SITES_DIR = self::NGINX_ROOT . '/conf.d';
    private const NGINX_MAP_FILE = self::NGINX_SITES_DIR . '/00-phpcp-proxy.conf';

    /** ชั้นหน้าของ http://localhost — ชั้นหลังใช้ไฟล์เดียวกับโหมด apache */
    private const NGINX_LOCALHOST_FILE = NginxDriver::LOCALHOST_FILE;

    /** เท่ากับค่าเริ่มต้นของเว็บทั่วไป — โฟลเดอร์พัฒนาไม่ได้ตั้งโควตาอัปโหลดไว้ */
    private const LOCALHOST_UPLOAD_LIMIT_MB = 64;

    private const APACHE_ROOT = '/etc/apache2';
    private const APACHE_SITES_DIR = self::APACHE_ROOT . '/sites-enabled';
    private const APACHE_PORTS_FILE = self::APACHE_ROOT . '/ports.conf';

    private const HTTP_PORT = 80;
    private const HTTPS_PORT = 443;

    /** พอร์ตของชั้นหลัง — ผูกกับ loopback เท่านั้น ไม่เปิดออกนอกเครื่อง */
    public const BACKEND_PORT = 8080;
    public const BACKEND = '127.0.0.1:' . self::BACKEND_PORT;

    /**
     * `remoteip` ไม่ใช่ของแถม — ดูเหตุผลข้อ 2 ในคำอธิบายคลาส
     *
     * ไม่ต้องมี proxy/proxy_http เพราะฝั่ง Apache เป็นผู้ถูก proxy ไม่ใช่ผู้ proxy
     * แต่ proxy_fcgi ยังต้องมีเพราะ PHP ยังส่งผ่าน FPM socket เหมือนเดิม
     */
    private const REQUIRED_MODULES = ['headers', 'rewrite', 'proxy', 'proxy_fcgi', 'remoteip'];

    private readonly ApacheDriver $apache;

    /**
     * @param bool $staticByNginx ให้ nginx ตอบไฟล์ static เอง — ค่าจาก `webserver.static_by_nginx`
     */
    public function __construct(
        private readonly Template $templates,
        private readonly bool $staticByNginx = true,
        /** null = ไม่เปิด http://localhost (ค่าเริ่มต้นของเครื่องที่ให้บริการจริง) */
        private readonly ?LocalhostSite $localhost = null,
    ) {
        $this->apache = new ApacheDriver($templates, $localhost);
    }

    public function name(): string
    {
        return 'nginx-proxy';
    }

    /**
     * unit ที่รับคำขอจากอินเทอร์เน็ต — ผู้เรียกที่ต้องการชั้นหลังใช้ backendUnit()
     */
    public function unit(): string
    {
        return 'nginx';
    }

    public function backendUnit(): string
    {
        return 'apache2';
    }

    /**
     * ไฟล์ของเว็บไซต์ต้องเป็นของกลุ่มที่ **Apache** อ่านได้ ไม่ใช่ nginx
     *
     * nginx ไม่แตะไฟล์ของเว็บเลยในโหมดนี้ (ส่งต่อทุกคำขอ) ถ้าตั้งเป็น nginx
     * Apache จะอ่านไฟล์ของลูกค้าไม่ได้แล้วทุกเว็บตอบ 403
     */
    public function runAsUser(): string
    {
        return $this->apache->runAsUser();
    }

    public function runAsGroup(): string
    {
        return $this->apache->runAsGroup();
    }

    public function ensureModules(Executor $executor, bool $withSsl = false): array
    {
        $enabledDir = $executor->path(self::APACHE_ROOT . '/mods-enabled');
        $enabled = [];

        // ชั้นหลังไม่ต้องมี mod_ssl แล้ว — TLS จบที่ nginx · ส่ง false เสมอโดยตั้งใจ
        foreach (self::REQUIRED_MODULES as $module) {
            if ($executor->exists($enabledDir . '/' . $module . '.load')) {
                continue;
            }

            $result = $executor->exec([$executor->path('/usr/sbin/a2enmod'), '-q', $module], timeout: 30);

            if ($result->ok()) {
                $enabled[] = $module;
            }
        }

        return $enabled;
    }

    /**
     * ไฟล์ที่ทั้งเครื่องใช้ร่วมกัน ไม่ใช่ของเว็บใดเว็บหนึ่ง
     *
     * คืนเป็น "เส้นทาง => เนื้อหา" ให้ผู้เรียกเอาไปใส่ ConfigTransaction เดียวกับ vhost
     * เพื่อให้ configtest ที่ตามมาตรวจทั้งชุดพร้อมกัน และ rollback พร้อมกันเมื่อไม่ผ่าน
     *
     * @return array<string,string>
     */
    public function globalFiles(Executor $executor): array
    {
        $files = [
            self::NGINX_MAP_FILE => $this->templates->render('nginx/proxy-map.conf.tpl', []),
            self::APACHE_PORTS_FILE => $this->templates->render('apache/backend-ports.conf.tpl', [
                'BACKEND_PORT' => self::BACKEND_PORT,
                'BACKUP_NOTE' => 'ถังพักของ ConfigTransaction ตอนเปลี่ยนโหมด',
            ]),
        ];

        // http://localhost ต้องมีสองไฟล์เหมือนเว็บอื่นในโหมดนี้ — ชั้นหน้าที่ nginx
        // กับชั้นหลังที่ Apache · ขาดไฟล์ชั้นหลังคือได้ 502 ขาดไฟล์ชั้นหน้าคือไม่มีใครรับ
        if ($this->localhost !== null) {
            $files[self::NGINX_LOCALHOST_FILE] = $this->templates->render('nginx/localhost.conf.tpl', [
                'HTTP_PORT' => self::HTTP_PORT,
                'BACKEND' => self::BACKEND,
                'ERROR_LOG' => $executor->path($this->localhost->errorLog()),
                'ACCESS_LOG' => $executor->path($this->localhost->accessLog()),
                'UPLOAD_LIMIT' => self::LOCALHOST_UPLOAD_LIMIT_MB
            ]);
            $files[ApacheDriver::LOCALHOST_FILE] = $this->apache->renderLocalhost($executor, self::BACKEND);
        }

        return $files;
    }

    public function vhostPath(Site $site): string
    {
        return self::NGINX_SITES_DIR . '/phpcp-' . $this->prefix($site) . $site->domain . '.conf';
    }

    /** เส้นทาง vhost ของชั้นหลัง — คนละไดเรกทอรีและคนละรูปแบบกับชั้นหน้า */
    public function backendVhostPath(Site $site): string
    {
        return self::APACHE_SITES_DIR . '/phpcp-' . $this->prefix($site) . $site->domain . '.conf';
    }

    /** @return list<string> */
    public function vhostPaths(Site $site): array
    {
        return [$this->vhostPath($site), $this->backendVhostPath($site)];
    }

    /**
     * ไฟล์ของเว็บนี้ **บวกไฟล์กลางของโหมด**
     *
     * ไฟล์กลางถูกเขียนซ้ำทุกครั้งที่มีการเปลี่ยนแปลงเว็บใดก็ตาม โดยตั้งใจ — เนื้อหาคงที่
     * การเขียนทับจึงไม่มีผลข้างเคียง แต่ทำให้เครื่องที่ไฟล์กลางหายไป (ผู้ดูแลลบเอง
     * หรืออัปเกรดแพ็กเกจ apache2 แล้ว ports.conf ถูกแทนที่) กลับมาถูกต้องเองในครั้งถัดไป
     * แทนที่จะพังค้างจนกว่าจะมีคนสังเกต · และทั้งชุดผ่าน configtest เดียวกันก่อน reload
     *
     * ไม่รวมอยู่ใน vhostPaths() เพราะการลบเว็บหนึ่งเว็บต้องไม่ลบไฟล์ที่ทั้งเครื่องใช้ร่วมกัน
     *
     * @return array<string,string>
     */
    public function vhostFiles(Site $site, Executor $executor): array
    {
        return $this->globalFiles($executor) + [
            $this->vhostPath($site) => $this->renderVhost($site, $executor),
            $this->backendVhostPath($site) => $this->renderBackendVhost($site, $executor),
        ];
    }

    /**
     * vhost ของ wildcard ต้องถูกอ่านท้ายสุดทั้งสองชั้น (PLAN-V2 เฟส E7)
     *
     * nginx เลือก server block จาก server_name ตามลำดับความเฉพาะเจาะจงจึงไม่ขึ้นกับชื่อไฟล์
     * แต่ Apache อ่านตามลำดับตัวอักษรและ vhost แรกที่ตรงชนะ — ใช้คำนำหน้าเดียวกันทั้งคู่
     * เพื่อไม่ให้ต้องจำว่าชั้นไหนต้องการอะไร
     */
    private function prefix(Site $site): string
    {
        return $site->hasWildcard() ? 'zz-wildcard-' : '';
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

        $common = [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'BACKEND' => self::BACKEND,
            'HTTP_PORT' => self::HTTP_PORT,
            'UPLOAD_LIMIT' => $site->uploadLimitMb,
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ];

        if ($site->sslMode === 'off') {
            return $this->templates->render('nginx/proxy-vhost.conf.tpl', $common + [
                'PROXY_BODY' => $this->proxyBody('http', $site, $executor),
            ]);
        }

        $certificate = $this->apache->certificatePath($site, $executor);

        return $this->templates->render('nginx/proxy-vhost-ssl.conf.tpl', $common + [
            'HTTPS_PORT' => self::HTTPS_PORT,
            'SSL_CERT' => $executor->path($certificate . '/fullchain.pem'),
            'SSL_KEY' => $executor->path($certificate . '/privkey.pem'),
            'SSL_MODE_LABEL' => $site->sslMode === 'forced' ? 'บังคับ HTTPS' : 'เปิดใช้งาน',
            'PROXY_BODY' => $this->proxyBody('https', $site, $executor),
            'HTTP_SECTION' => $this->httpSection($site, $executor),
            'HSTS_HEADER' => $this->hstsHeader($site),
        ]);
    }

    /**
     * บล็อกส่งต่อคำขอ พร้อมส่วนที่ให้ nginx ตอบไฟล์ static เองเมื่อทำได้อย่างปลอดภัย
     *
     * ลำดับของ location สำคัญ: โฟลเดอร์ที่ต้องบังคับผ่าน Apache ใช้ `^~` ซึ่งชนะ
     * location แบบ regex ของไฟล์ static เสมอ จึงต้องประกาศก่อน — ถ้าสลับลำดับ
     * ไฟล์ static ในโฟลเดอร์ที่ป้องกันไว้จะถูก nginx ตอบไปโดยไม่ผ่านกฎของลูกค้า
     */
    private function proxyBody(string $scheme, Site $site, Executor $executor): SafeBlock
    {
        $scan = $this->staticPolicy($executor, $site);

        return new SafeBlock($this->templates->render('nginx/proxy-body.conf.tpl', [
            'BACKEND' => self::BACKEND,
            'SCHEME' => $scheme,
            'FORCE_PROXY_DIRS' => $this->forceProxyDirs($scan['proxy_dirs']),
            'STATIC_SECTION' => $scan['static_ok']
                ? new SafeBlock($this->templates->render('nginx/proxy-static.conf.tpl', [
                    'DOCROOT' => $executor->path($site->docroot()),
                ]))
                : new SafeBlock(
                    "\n    # เว็บนี้ให้ Apache ตอบทุกอย่างรวมทั้งไฟล์ static เพราะ .htaccess ที่รากเว็บ\n"
                    . "    # มีกฎที่ nginx ทำแทนไม่ได้ (ควบคุมการเข้าถึงหรือแก้ response header)\n"
                    . '    # — ดู Phpcp\\Driver\\WebServer\\HtaccessScan',
                ),
        ]));
    }

    /**
     * ให้ nginx ตอบ static เองไหม และโฟลเดอร์ไหนต้องบังคับผ่าน Apache
     *
     * @return array{static_ok:bool,proxy_dirs:list<string>}
     */
    private function staticPolicy(Executor $executor, Site $site): array
    {
        if (!$this->staticByNginx) {
            return ['static_ok' => false, 'proxy_dirs' => []];
        }

        return HtaccessScan::inspect($executor, $site->docroot());
    }

    /**
     * location ของโฟลเดอร์ที่มี .htaccess ชนิดที่ nginx ทำแทนไม่ได้
     *
     * `^~` บอก nginx ว่า "เจอคำนำหน้านี้แล้วหยุด ไม่ต้องลอง regex ต่อ" ซึ่งเป็นสิ่ง
     * เดียวที่กันไม่ให้ location ของไฟล์ static ไปคว้าคำขอในโฟลเดอร์นี้ไปตอบเอง
     *
     * @param list<string> $dirs
     */
    private function forceProxyDirs(array $dirs): SafeBlock
    {
        if ($dirs === []) {
            return new SafeBlock('');
        }

        $out = "\n    # โฟลเดอร์ที่มีกฎ .htaccess ซึ่ง nginx ทำแทนไม่ได้ — ต้องผ่าน Apache ทุกคำขอ\n";

        foreach ($dirs as $dir) {
            $out .= sprintf(
                // ตัวแปรของ nginx ต้องอยู่ในสตริงเดี่ยว ไม่งั้น PHP แทนค่าเป็นค่าว่าง
                // แล้วได้ config ที่ส่ง Host เปล่าไปให้ Apache — ทุกคำขอตกไป vhost แรก
                "    location ^~ %s {\n"
                . "        proxy_pass http://%s;\n"
                . '        proxy_set_header Host            $host;' . "\n"
                . '        proxy_set_header X-Real-IP       $remote_addr;' . "\n"
                . '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;' . "\n"
                . "    }\n",
                Template::assertValue('location', $dir),
                self::BACKEND,
            );
        }

        return new SafeBlock($out);
    }

    /**
     * บล็อก :80 ตอนบังคับ HTTPS — ยกเว้นเส้นทาง acme ที่ประกาศไว้ก่อนหน้าแล้ว
     *
     * `location /` ที่นี่ทับ location เดียวกันไม่ได้ จึงต้องเลือกอย่างใดอย่างหนึ่ง:
     * โหมดบังคับ = redirect · โหมดเปิดใช้งานเฉย ๆ = ส่งต่อไป backend ตามปกติ
     */
    private function httpSection(Site $site, Executor $executor): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return $this->proxyBody('http', $site, $executor);
        }

        return new SafeBlock(
            "\n    # บังคับ HTTPS — ยกเว้นเส้นทางตรวจสอบของ Let's Encrypt ด้านบน\n"
            . "    location / {\n"
            . "        return 301 https://\$host\$request_uri;\n"
            . '    }',
        );
    }

    private function hstsHeader(Site $site): SafeBlock
    {
        if ($site->sslMode !== 'forced') {
            return new SafeBlock('');
        }

        // เหตุผลของ 6 เดือนและการไม่ใส่ preload เหมือน ApacheDriver ทุกประการ
        return new SafeBlock(
            "\n    add_header Strict-Transport-Security \"max-age=15768000\" always;",
        );
    }

    /**
     * vhost ของชั้นหลัง — ใช้ body เดียวกับโหมด apache ล้วน
     *
     * ใช้ซ้ำโดยตั้งใจ: กฎกันไฟล์ `.env`/`.git`, `AllowOverride All`, การส่ง PHP เข้า
     * FPM pool ของลูกค้า ต้องเหมือนกันทุกโหมด ถ้าคัดลอกไปอีกไฟล์วันหนึ่งจะแก้ที่เดียว
     * แล้วลืมอีกที่ กลายเป็นช่องโหว่ที่เปิดเฉพาะบางโหมด
     */
    public function renderBackendVhost(Site $site, Executor $executor): string
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
                'HTTP_PORT' => self::BACKEND,
            ]);
        }

        $body = new SafeBlock($this->templates->render('apache/vhost-body.conf.tpl', [
            // ไดเรกทอรีของผู้ดูแล — vhost อ่านเป็นอันสุดท้าย ค่าที่นั่นจึงชนะค่าเริ่มต้น
            'CUSTOM_DIR' => $executor->path(CustomConfig::siteDirectory('apache', $site->domain)),
            'DOCROOT' => $executor->path($site->docroot()),
            'FPM_SOCKET' => $executor->path($site->fpmSocket()),
            'ERROR_LOG' => $executor->path($site->errorLog()),
            'ACCESS_LOG' => $executor->path($site->accessLog()),
        ]));

        return $this->templates->render('apache/backend-vhost.conf.tpl', [
            'DOMAIN' => $site->domain,
            'SERVER_ALIASES' => $aliases,
            'SITE_BODY' => $body,
            'SITE_USER' => $site->systemUser(),
            'PHP_VERSION' => $site->phpVersion,
            'BACKEND' => self::BACKEND,
            'BACKEND_PORT' => self::BACKEND_PORT,
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * ต้องผ่าน **ทั้งสองชั้น** ถึงจะนับว่าผ่าน
     *
     * ตรวจ Apache ก่อนเพราะเป็นชั้นที่เสิร์ฟจริง · ถ้าปล่อยให้ nginx ผ่านแล้ว reload
     * ทั้งที่ชั้นหลังพัง ผลคือทุกเว็บตอบ 502 ซึ่งดูเหมือนปัญหาของ nginx
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        [$apacheOk, $apacheOut] = $this->apache->testConfig($executor);

        if (!$apacheOk) {
            return [false, "ชั้นหลัง (Apache) ไม่ผ่าน:\n" . $apacheOut];
        }

        $result = $executor->exec([$executor->path('/usr/sbin/nginx'), '-t'], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        if (!$result->ok() && !self::isOnlyBindFailure($output)) {
            return [false, "ชั้นหน้า (nginx) ไม่ผ่าน:\n" . $output];
        }

        return [true, trim($apacheOut . "\n" . $output)];
    }

    /**
     * `nginx -t` ล้มเพราะจองพอร์ตไม่ได้เท่านั้นหรือไม่ — ไม่ใช่เพราะไฟล์ผิด
     *
     * **การตรวจ config ของ nginx เปิด listening socket จริง** ไม่ได้อ่านแค่ไวยากรณ์
     * ระหว่างสลับโหมด Apache ยังถือพอร์ต 80/443 อยู่จนกว่าจะรีสตาร์ต การตรวจจึงล้ม
     * ด้วย EADDRINUSE ทั้งที่ nginx เพิ่งบอกเองว่า "syntax is ok" — ถ้าถือว่าไม่ผ่าน
     * ทรานแซกชันจะ rollback ทิ้งทุกครั้งแล้วสลับโหมดไม่ได้เลยสักครั้ง
     *
     * ปลอดภัยที่จะผ่านต่อ เพราะการ bind จริงเกิดตอนสตาร์ตอยู่แล้ว และถ้าตอนนั้นยังจอง
     * ไม่ได้ `ServiceAction` จะบอกว่าใครถือพอร์ตอยู่พร้อมวิธีแก้ (ไม่ใช่ข้อความของ systemd)
     *
     * เงื่อนไขเข้มโดยตั้งใจ: ต้องเห็น "syntax is ok" และทุกบรรทัด emerg ต้องเป็นเรื่อง
     * bind เท่านั้น — ผิดพลาดอย่างอื่นแม้บรรทัดเดียวก็ถือว่าไม่ผ่านตามปกติ
     */
    public static function isOnlyBindFailure(string $output): bool
    {
        if (!str_contains($output, 'syntax is ok')) {
            return false;
        }

        $emergencies = 0;

        foreach (explode("\n", $output) as $line) {
            if (!str_contains($line, '[emerg]')) {
                continue;
            }

            $emergencies++;

            if (!str_contains($line, 'bind() to') && !str_contains($line, 'still could not bind')) {
                return false;
            }
        }

        return $emergencies > 0;
    }

    /**
     * reload ชั้นหลังก่อนชั้นหน้าเสมอ
     *
     * ระหว่างที่ Apache กำลังโหลดค่าใหม่ nginx ที่ยังถือค่าเดิมอยู่จะ retry ให้เอง
     * แต่ถ้าสลับลำดับ nginx จะส่งคำขอไป vhost ที่ยังไม่มีอยู่จริงแล้วตอบ 502 ให้ผู้ใช้
     */
    public function reload(Executor $executor): void
    {
        $this->reloadUnit($executor, $this->backendUnit());
        $this->reloadUnit($executor, $this->unit());
    }

    private function reloadUnit(Executor $executor, string $unit): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $unit], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "เขียนค่าตั้งเรียบร้อยแล้วแต่สั่ง reload %s ไม่สำเร็จ — ค่าตั้งใหม่จะยังไม่มีผลจนกว่าจะ reload สำเร็จ\n\n%s",
                $unit,
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    /** ต้องมีครบทั้งสองตัว — ขาดตัวใดตัวหนึ่งโหมดนี้ทำงานไม่ได้เลย */
    public function isInstalled(Executor $executor): bool
    {
        return $this->apache->isInstalled($executor)
            && $executor->exists($executor->path('/usr/sbin/nginx'));
    }
}
