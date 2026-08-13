<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Driver\WebServer\LocalhostSite;
use Phpcp\Driver\WebServer\NginxDriver;
use Phpcp\Driver\WebServer\NginxProxyDriver;
use Phpcp\Driver\WebServer\WebServerDriver;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * ฐานร่วมของ capability ที่ทำงานกับเว็บไซต์
 *
 * รวมการประกอบ driver และการโหลดเว็บไซต์ไว้ที่เดียว เพื่อให้ทุก capability
 * เห็นเส้นทางไฟล์และเวอร์ชัน PHP เดียวกันเสมอ
 */
abstract class SiteCapability implements Capability
{
    /**
     * @param Context $context
     */
    protected function provisioner(Context $context): SiteProvisioner
    {
        return self::provisionerFor($context);
    }

    /**
     * ตัวเดียวกับ provisioner() แต่เรียกได้จากนอกลำดับชั้นนี้
     *
     * `customer.*` บางตัวต้องเขียน vhost/pool ใหม่ (เช่นตอนเปลี่ยนรูปทรงไฟล์ของบัญชี)
     * แต่สืบทอดจาก `CustomerCapability` จึงเข้าถึงเมธอด protected ตัวบนไม่ได้ ·
     * ให้เรียกทางนี้แทนการประกอบ driver ขึ้นเองซ้ำ — ประกอบเองแปลว่าอีกไม่นาน
     * สองที่จะเห็นเว็บเซิร์ฟเวอร์หรือ shared_owner ไม่ตรงกัน
     */
    public static function provisionerFor(Context $context): SiteProvisioner
    {
        $templates = new Template($context->config->paths->templates());

        return new SiteProvisioner(
            self::webServer($context, $templates),
            new FpmManager($templates),
            $context->config->sharedOwner(),
        );
    }

    /**
     * เลือกเว็บเซิร์ฟเวอร์ตามค่าตั้ง
     *
     * **ฐานข้อมูลมาก่อน `config.php`** — ค่านี้ต้องเปลี่ยนได้จากหน้าจอ ไม่ใช่ให้ผู้ดูแล
     * ไป `sed` ไฟล์เอาเอง · `config.php` ยังเป็นค่าเริ่มต้นสำหรับเครื่องที่ติดตั้งไว้
     * ก่อนหน้านี้และยังไม่เคยเลือกจากหน้าจอ (ค่าในตารางเป็นสตริงว่าง)
     *
     * ค่าที่ไม่รู้จักตกกลับไปใช้ Apache แทนที่จะโยน error — ถ้าพิมพ์ผิดแล้วระบบไม่ยอม
     * ทำงานเลย ผู้ดูแลจะเข้าหน้าเว็บไปแก้ค่าไม่ได้ด้วย กลายเป็นล็อกตัวเองออกจากระบบ
     */
    protected static function webServer(Context $context, Template $templates): WebServerDriver
    {
        $localhost = self::localhostSite($context);

        return match (self::webServerMode($context)) {
            'nginx' => new NginxDriver($templates, $localhost),
            // nginx ชั้นหน้า + Apache ชั้นหลัง — โหมดเดียวที่ .htaccess ใช้งานได้บน nginx
            'nginx-proxy' => new NginxProxyDriver(
                $templates,
                (new SettingsRepository($context->db))->bool('webserver.static_by_nginx'),
                $localhost,
            ),
            default => new ApacheDriver($templates, $localhost),
        };
    }

    /**
     * http://localhost ของเครื่องพัฒนา — null เมื่อไม่ได้ตั้ง `sites.localhost_docroot`
     *
     * อ่านจากไฟล์ตั้งค่าอย่างเดียว ไม่ใช่ตารางตั้งค่า เพราะเป็นคุณสมบัติของ**เครื่อง**
     * ไม่ใช่ของผู้ใช้ — และไม่ควรมีปุ่มบนหน้าเว็บที่กดแล้วเสิร์ฟโฟลเดอร์ไหนก็ได้บนเครื่อง
     */
    protected static function localhostSite(Context $context): ?LocalhostSite
    {
        $docroot = $context->config->localhostDocroot();

        return $docroot === ''
            ? null
            : new LocalhostSite($docroot, $context->config->localhostPhp());
    }

    /** โหมดที่ใช้งานอยู่จริง — ตารางตั้งค่ามาก่อน แล้วค่อยถอยไป config.php */
    public static function webServerMode(Context $context): string
    {
        $stored = trim((new SettingsRepository($context->db))->get('webserver.mode'));

        return $stored !== '' ? $stored : $context->config->string('webserver', 'apache');
    }

    /**
     * @param Context $context
     */
    protected function repository(Context $context): SiteRepository
    {
        return new SiteRepository($context->db);
    }

    /** โหลดเว็บไซต์ตาม id พร้อม alias — โยน error ถ้าไม่พบ */
    protected function loadSite(Context $context, int $siteId): Site
    {
        $site = $this->repository($context)->load($siteId);

        if ($site === null) {
            throw new ValidationError("ไม่พบเว็บไซต์รหัส {$siteId}");
        }

        return $site;
    }

    /**
     * ผู้ดูแลเว็บไซต์แตะได้เฉพาะเว็บของตัวเอง
     *
     * ตรวจซ้ำที่ชั้นนี้แม้ web tier ตรวจไปแล้ว เพราะ agent ต้องไม่เชื่อผู้เรียก —
     * ใบรับรองผูกกับโดเมน ถ้าข้ามการตรวจนี้ได้ ก็ขอใบของโดเมนคนอื่นได้ทันที
     */
    protected function assertSiteAccess(Context $context, int $siteId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :user',
            ['id' => $siteId, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์กับเว็บไซต์ที่ระบุ');
        }
    }

    /** ตรวจเวอร์ชัน PHP ว่าอยู่ในรายการที่ระบบรู้จัก */
    protected static function assertPhpVersion(string $version): string
    {
        $version = Validator::phpVersion($version);

        if (!in_array($version, ServiceCatalog::PHP_VERSIONS, true)) {
            throw new ValidationError("ระบบไม่รองรับ PHP เวอร์ชัน {$version}");
        }

        return $version;
    }

    /**
     * ตรวจสอบว่าเป็นเครื่องนักพัฒนาในเครื่อง/สภาวะทดสอบหรือไม่
     *
     * ตัวเลือก:
     *   - ถ้า config มี log.force_hosts_update_for_test_domains = true
     *     จะแก้ไข /etc/hosts สำหรับโดเมน .test เสมอ แม้มี BIND/named รันอยู่
     *   - ถ้าไม่มี DNS server (named/bind9) รันอยู่ → เป็น local environment
     *
     * บน Server จริงๆ ไม่ต้องตั้งค่านี้ — จะไม่แตะ hosts เพราะมี DNS จัดการอยู่แล้ว
     *
     * @param Context $context
     */
    protected function isLocalEnvironment(\Phpcp\Agent\Executor\Executor $executor, Context $context): bool
    {
        // ถ้าตั้งค่าให้บังคับแก้ไข hosts สำหรับโดเมน .test
        if ($context->config->bool('log.force_hosts_update_for_test_domains', false)) {
            return true;
        }

        // หรือถ้าไม่มี DNS server รันอยู่ (ไม่มี named หรือ bind9)
        $namedStatus = ServiceProbe::read($executor, 'named');
        $bind9Status = ServiceProbe::read($executor, 'bind9');
        return !($namedStatus['running'] || $bind9Status['running']);
    }

    /**
     * บันทึกหรือลบข้อมูลในไฟล์ /etc/hosts สำหรับโดเมนย่อยหรือโดเมนหลัก .test
     *
     * อ่าน/เขียน /etc/hosts ตรง ๆ ผ่าน PHP native ไม่ผ่าน executor เพราะ:
     *   - agentd รันด้วย root อยู่แล้ว มีสิทธิ์เขียนโดยตรง
     *   - /etc/hosts ไม่ใช่ config ของ web service จึงไม่ควรถูก SandboxExecutor remap
     *     ซึ่งจะทำให้เขียนเข้า prefix/etc/hosts แทนที่จะเป็นไฟล์จริง
     */
    protected function updateHostsFile(\Phpcp\Agent\Executor\Executor $executor, string $domain, bool $add): void
    {
        if (!str_ends_with($domain, '.test')) {
            return;
        }

        $hostsPath = '/etc/hosts';

        // อ่านตรง ๆ ด้วย PHP native — ไม่ผ่าน executor เพื่อหลีกเลี่ยง sandbox path remap
        $content = @file_get_contents($hostsPath);
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $newLines[] = $line;
                continue;
            }
            $parts = preg_split('/\s+/', $trimmed);
            if (count($parts) >= 2 && $parts[0] === '127.0.0.1' && strtolower($parts[1]) === strtolower($domain)) {
                $found = true;
                if ($add) {
                    $newLines[] = $line;
                }
            } else {
                $newLines[] = $line;
            }
        }

        if ($add && !$found) {
            $newLines[] = "127.0.0.1\t" . $domain;
        }

        $newContent = implode("\n", $newLines);
        $newContent = rtrim($newContent) . "\n";

        // เขียนแบบ atomic ผ่านไฟล์ชั่วคราวแล้ว rename เพื่อกันไฟล์ครึ่ง ๆ
        // ทำงานได้เฉพาะเมื่อ process เป็น root (agentd) เท่านั้น
        $tmp = $hostsPath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $newContent, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $hostsPath)) {
            @unlink($tmp);
        }
    }
}
