<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Config;
use Phpcp\Support\BinaryPath;
use Phpcp\Support\Validator;

/**
 * ใบรับรองของ **หน้าจัดการเอง** — คนละเรื่องกับใบรับรองของเว็บไซต์ที่ลูกค้าใช้
 *
 * ## ทำไมต้องมีตัวนี้แยกออกมา
 *
 * Apache ของ panel อ่านไฟล์ที่ `/etc/phpcp/tls/panel.crt` ตายตัวจาก `httpd.conf` ที่ตัวติดตั้ง
 * สร้างไว้ · เดิมจึงเปลี่ยนเป็นใบจริงได้ด้วยการแก้ไฟล์ด้วยมือเท่านั้น แล้วผู้ดูแลก็คลิกผ่าน
 * คำเตือนใบรับรองทุกวันไปเรื่อย ๆ — ซึ่งเป็นการฝึกให้คนเพิกเฉยต่อคำเตือนที่วันหนึ่งจะเป็นของจริง
 *
 * ## คัดลอกไฟล์ ไม่ใช่ symlink
 *
 * ทางที่ดูสั้นกว่าคือ symlink ไปที่ `/etc/letsencrypt/live/...` แต่มีปัญหาสองข้อที่ทำให้ใช้ไม่ได้:
 *
 *   1. **`RollbackGuard` คืนค่าด้วยการ *เขียนเนื้อไฟล์*** — ถ้าปลายทางเป็น symlink การคืนค่า
 *      จะเขียนทะลุไปทับใบรับรองตัวจริงของ Let's Encrypt ซึ่งเว็บไซต์ของลูกค้าใช้อยู่ด้วย
 *   2. Apache อ่านไฟล์ตอนสตาร์ตในฐานะ root ก็จริง แต่การพึ่งสิทธิ์ของไดเรกทอรีอื่นทำให้
 *      ความถูกต้องขึ้นกับสิ่งที่อยู่นอกความควบคุมของ panel
 *
 * คัดลอกแล้วให้ **deploy hook ของ certbot** คัดลอกใหม่ทุกครั้งที่ต่ออายุ ({@see hookScript()})
 * — ขาด hook นี้คือใบจะหมดอายุใน 90 วันแล้วกลับไปเจอคำเตือนอีก ทั้งที่ไฟล์บนดิสก์ถูกต้อง
 *
 * ## ลำดับการตรวจก่อนสลับ
 *
 * ตรวจที่นี่ก่อนสามข้อ (มีไฟล์ · กุญแจกับใบเป็นคู่กันจริง · ยังไม่หมดอายุ) แล้วให้
 * **ตัวตรวจของ Apache เอง** ตัดสินอีกชั้นผ่าน {@see ConfigTransaction} · คู่กุญแจที่ไม่ตรงกัน
 * ทำให้ Apache สตาร์ตไม่ขึ้นในการรีบูตครั้งถัดไป ซึ่งเป็นการล็อกตัวเองออกจากเครื่องแบบที่
 * ไม่มีใครรู้จนกว่าจะรีบูต
 */
final class PanelCertificate
{
    /** ไฟล์ที่ httpd.conf ของ panel ชี้ถึงตายตัว */
    public const CERT = '/etc/phpcp/tls/panel.crt';
    public const KEY = '/etc/phpcp/tls/panel.key';

    /** ใบที่ตัวติดตั้งสร้างไว้ให้ — เก็บไว้เป็นทางกลับเสมอ */
    public const SELF_SIGNED_CERT = '/etc/phpcp/tls/panel.selfsigned.crt';
    public const SELF_SIGNED_KEY = '/etc/phpcp/tls/panel.selfsigned.key';

    /** hook ที่ certbot เรียกหลังต่ออายุใบสำเร็จ */
    public const HOOK = '/etc/letsencrypt/renewal-hooks/deploy/phpcp-panel-cert.sh';

    /** unit ของเว็บเซิร์ฟเวอร์ที่ให้บริการหน้าจัดการ */
    public const UNIT = 'phpcp-web';

    /** @var list<string> */
    public const OPENSSL_PATHS = ['/usr/bin/openssl', '/bin/openssl'];

    /** ที่อยู่ของใบรับรองที่ certbot ออกให้โดเมนหนึ่ง */
    public static function sourcePaths(string $domain): array
    {
        $domain = Validator::domain($domain);

        return [
            'cert' => '/etc/letsencrypt/live/' . $domain . '/fullchain.pem',
            'key' => '/etc/letsencrypt/live/' . $domain . '/privkey.pem',
        ];
    }

    /**
     * อ่านสภาพปัจจุบันของใบที่หน้าจัดการใช้อยู่
     *
     * @return array{domain:string,self_signed:bool,subject:string,issuer:string,not_after:int,days_left:int,hook:bool}
     */
    public function status(Executor $executor, string $configuredDomain): array
    {
        $info = $this->inspect($executor, self::CERT);

        return [
            'domain' => $configuredDomain,
            // ใบที่ผู้ออกกับผู้ถือเป็นคนเดียวกัน = เซ็นเอง · ไม่ต้องเดาจากชื่อไฟล์
            'self_signed' => $configuredDomain === '' || $info['issuer'] === $info['subject'],
            'subject' => $info['subject'],
            'issuer' => $info['issuer'],
            'not_after' => $info['not_after'],
            'days_left' => $info['not_after'] > 0 ? (int) floor(($info['not_after'] - time()) / 86400) : 0,
            'hook' => $executor->exists($executor->path(self::HOOK)),
        ];
    }

    /**
     * เนื้อไฟล์ที่จะเอาไปวางเป็นใบของหน้าจัดการ พร้อมตรวจว่าใช้ได้จริง
     *
     * @return array{cert:string,key:string}
     */
    public function read(Executor $executor, string $certPath, string $keyPath): array
    {
        foreach ([$certPath, $keyPath] as $path) {
            if (!$executor->exists($executor->path($path))) {
                throw new ValidationError(
                    'ไม่พบไฟล์ใบรับรองที่ ' . $path . ' — ขอใบรับรองให้โดเมนนี้ก่อน',
                );
            }
        }

        $cert = $executor->readFile($executor->path($certPath));
        $key = $executor->readFile($executor->path($keyPath));

        $this->assertUsable($executor, $certPath, $keyPath);

        return ['cert' => $cert, 'key' => $key];
    }

    /**
     * ใบกับกุญแจต้องเป็นคู่กันจริงและยังไม่หมดอายุ
     *
     * **คู่ที่ไม่ตรงกันคือการล็อกตัวเองออกจากเครื่อง** — Apache สตาร์ตไม่ขึ้นในการรีบูต
     * ครั้งถัดไป และไม่มีอะไรบอกจนกว่าจะรีบูต · เทียบด้วยลายนิ้วมือของกุญแจสาธารณะซึ่ง
     * เป็นวิธีเดียวที่ตอบได้แน่นอน (ชื่อโดเมนที่ตรงกันไม่ได้แปลว่าเป็นคู่กัน)
     */
    private function assertUsable(Executor $executor, string $certPath, string $keyPath): void
    {
        $openssl = BinaryPath::resolve($executor, self::OPENSSL_PATHS, 'openssl');

        $certPub = $executor->exec(
            [$openssl, 'x509', '-noout', '-pubkey', '-in', $executor->path($certPath)],
            timeout: 10,
        );
        $keyPub = $executor->exec(
            [$openssl, 'pkey', '-pubout', '-in', $executor->path($keyPath)],
            timeout: 10,
        );

        if (!$certPub->ok() || !$keyPub->ok()) {
            throw new ValidationError(
                "อ่านใบรับรองหรือกุญแจไม่ได้ — ไฟล์อาจเสียหาย\n"
                . trim($certPub->stderr . ' ' . $keyPub->stderr),
            );
        }

        if (trim($certPub->output()) !== trim($keyPub->output())) {
            throw new ValidationError(
                'ใบรับรองกับกุญแจไม่ใช่คู่กัน — ใช้แล้วเว็บเซิร์ฟเวอร์ของหน้าจัดการ'
                . 'จะสตาร์ตไม่ขึ้นในการรีบูตครั้งถัดไป',
            );
        }

        $info = $this->inspect($executor, $certPath);

        if ($info['not_after'] > 0 && $info['not_after'] < time()) {
            throw new ValidationError(sprintf(
                'ใบรับรองนี้หมดอายุไปแล้วเมื่อ %s — ต่ออายุก่อนแล้วลองใหม่',
                date('Y-m-d', $info['not_after']),
            ));
        }
    }

    /**
     * ข้อมูลในใบรับรอง — คืนค่าว่างเมื่ออ่านไม่ได้ ไม่โยนออก
     *
     * ใช้ตอนแสดงสถานะด้วย ซึ่งต้องทำงานได้แม้ไฟล์จะเสีย · หน้าจอที่พังเพราะใบรับรองเสีย
     * คือการปิดทางเดียวที่ผู้ดูแลจะเข้ามาแก้ได้
     *
     * @return array{subject:string,issuer:string,not_after:int}
     */
    private function inspect(Executor $executor, string $path): array
    {
        $empty = ['subject' => '', 'issuer' => '', 'not_after' => 0];

        if (!$executor->exists($executor->path($path))) {
            return $empty;
        }

        try {
            $openssl = BinaryPath::resolve($executor, self::OPENSSL_PATHS, 'openssl');
        } catch (\Throwable) {
            return $empty;
        }

        $result = $executor->exec(
            [$openssl, 'x509', '-noout', '-subject', '-issuer', '-enddate', '-in', $executor->path($path)],
            timeout: 10,
        );

        if (!$result->ok()) {
            return $empty;
        }

        $out = $result->output();
        $subject = $this->field($out, 'subject=');
        $issuer = $this->field($out, 'issuer=');
        $notAfter = $this->field($out, 'notAfter=');

        return [
            'subject' => $subject,
            'issuer' => $issuer,
            'not_after' => $notAfter === '' ? 0 : (int) max(0, strtotime($notAfter)),
        ];
    }

    private function field(string $output, string $prefix): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, $prefix)) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return '';
    }

    /**
     * สคริปต์ที่ certbot เรียกหลังต่ออายุสำเร็จ
     *
     * เรียก `phpcp panel:cert-sync` แทนที่จะคัดลอกไฟล์เอง เพราะคำสั่งนั้นรู้ว่าตอนนี้
     * หน้าจัดการผูกกับโดเมนไหนอยู่ (อ่านจากค่าตั้ง) · เขียน hook เป็นตรรกะคัดลอกตรง ๆ
     * จะกลายเป็นข้อมูลชุดที่สองที่ต้องคอยแก้ให้ตรงกัน แล้ววันหนึ่งมันจะไม่ตรง
     *
     * `|| true` ท้ายบรรทัดโดยตั้งใจ — hook ที่คืนค่าไม่เป็นศูนย์ทำให้ certbot รายงานว่า
     * การต่ออายุล้มเหลวทั้งที่ใบใหม่ออกมาแล้วเรียบร้อย ซึ่งทำให้คนไล่หาปัญหาผิดที่
     */
    public static function hookScript(string $phpBinary, string $cliPath): string
    {
        return "#!/bin/sh\n"
            . "# สร้างโดย phpcp — ห้ามแก้ไขด้วยมือ\n"
            . "# คัดลอกใบรับรองที่เพิ่งต่ออายุไปให้หน้าจัดการ แล้วสั่งโหลดใหม่แบบไม่ตัดการเชื่อมต่อ\n"
            . sprintf("%s %s panel:cert-sync >/dev/null 2>&1 || true\n", $phpBinary, $cliPath);
    }

    /** โหลดค่าใหม่แบบ graceful — คำขอที่กำลังตอบอยู่ (รวมถึงของผู้ที่กดปุ่ม) ต้องไม่ถูกตัด */
    public function reload(Executor $executor): void
    {
        $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'reload', self::UNIT],
            timeout: 30,
        );
    }

    /** ตัวตรวจของ Apache เอง — ตัดสินว่าไฟล์ที่เพิ่งวางใช้งานได้จริงไหม */
    public function checkConfig(Executor $executor, Config $config): array
    {
        $httpd = $this->httpdBinary($executor);

        if ($httpd === null) {
            // ไม่มีไบนารีให้ตรวจ (โหมดพัฒนา) — การตรวจคู่กุญแจก่อนหน้ายังทำงานอยู่
            return [true, ''];
        }

        $confDir = rtrim($config->paths->etc, '/') . '/httpd';
        $result = $executor->exec(
            [$httpd, '-d', $executor->path($confDir), '-f', $executor->path($confDir . '/httpd.conf'), '-t'],
            timeout: 20,
        );

        if (!$result->ok()) {
            return [false, trim($result->output() . $result->stderr)];
        }

        return [true, ''];
    }

    private function httpdBinary(Executor $executor): ?string
    {
        foreach (['/usr/sbin/apache2', '/usr/sbin/httpd'] as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}
