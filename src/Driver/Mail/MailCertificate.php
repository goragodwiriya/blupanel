<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * ใบรับรองของ mail hostname — PLAN-MAIL เฟส M3
 *
 * ## ทำไมถึงไม่ขอใบเองที่นี่
 *
 * ใบของ `mail.example.com` เป็นใบธรรมดาใบหนึ่ง ไม่มีอะไรพิเศษเลย · การขอใบต้องพิสูจน์
 * ว่าคุมชื่อนั้นได้ ซึ่งวิธีเดียวที่ใช้ได้จริงบนเครื่องที่มีเว็บเซิร์ฟเวอร์ถือพอร์ต 80
 * อยู่แล้วคือ webroot ของเว็บไซต์ที่รับชื่อนั้น — คือเส้นทางเดียวกับปุ่มขอใบรับรอง
 * ในหน้า SSL ที่มีอยู่แล้วทุกประการ
 *
 * ถ้าเขียนตัวขอใบใบที่สองขึ้นมาที่นี่ จะได้สองเส้นทางที่ทำเรื่องเดียวกันแต่ต่อ ACME
 * คนละที่ ต่ออายุคนละแบบ และพังคนละอาการ · แทนที่จะทำแบบนั้น ที่นี่ทำสิ่งที่ยังไม่มี
 * ใครทำ: **หาใบที่ครอบคลุมชื่อนี้อยู่แล้วบนเครื่อง แล้วบอก Postfix กับ Dovecot**
 *
 * ผู้ดูแลจึงเพิ่ม `mail.example.com` เป็นโดเมนของเว็บไซต์ กดปุ่มขอใบรับรองที่มีอยู่
 * แล้วเมลได้ใบจริงตามไปเอง — ไม่มีขั้นตอนใหม่ให้ต้องเรียนรู้
 *
 * ## สิ่งที่พลาดง่ายที่สุดคือการต่ออายุ
 *
 * ใบของ Let's Encrypt อายุ 90 วันและ certbot ต่อให้เองโดยไม่ผ่าน panel เลย · Postfix
 * อ่านไฟล์ใบใหม่ทุกครั้งที่มีการเชื่อมต่อ (smtpd เกิดใหม่ตลอด) แต่ **Dovecot อ่าน
 * ตอนสตาร์ตแล้วถือไว้** — ไม่มีใครสั่ง reload หลังต่ออายุ โปรแกรมเมลของลูกค้าจะเจอ
 * ใบที่หมดอายุไปเรื่อย ๆ ทั้งที่ไฟล์บนดิสก์ถูกต้อง · ดู `changedSince()`
 */
final class MailCertificate
{
    /**
     * ใบที่ดิสโทรสร้างให้ตอนติดตั้ง — ใช้ไปก่อนเมื่อยังไม่มีใบจริง
     *
     * ไม่มีใครเชื่อถือใบนี้ โปรแกรมเมลจะเตือนทุกครั้ง · แต่ทางเลือกอีกทางคือไม่มี TLS
     * เลย ซึ่งแปลว่ารหัสผ่านของทุกกล่องวิ่งเป็นข้อความเปล่า — แย่กว่ากันมาก ·
     * หน้าความพร้อมของเมลเป็นคนบอกว่าตอนนี้ยังใช้ใบนี้อยู่
     *
     * **มีอยู่จริงเสมอบนเครื่องที่ลง Dovecot** — `dovecot-core` มี `ssl-cert` เป็น
     * Depends (ไม่ใช่ Recommends) · เขียนเส้นทางนี้ลงไฟล์ตั้งค่าจึงไม่มีทางได้เดมอน
     * ที่สตาร์ตไม่ขึ้นเพราะชี้ไปไฟล์ที่ไม่มี — ตรวจแล้วก่อนเลือกใช้เป็นค่าถอย
     */
    public const DEFAULT_CERT = '/etc/ssl/certs/ssl-cert-snakeoil.pem';
    public const DEFAULT_KEY = '/etc/ssl/private/ssl-cert-snakeoil.key';

    public function __construct(private readonly CertbotManager $certbot)
    {
    }

    /**
     * เส้นทางใบที่จะเขียนลงไฟล์ตั้งค่า — ว่างเมื่อไหร่ก็ถอยไปใช้ใบของดิสโทร
     *
     * @return array{cert:string,key:string}
     */
    public static function pathsOrDefault(string $cert, string $key): array
    {
        // ต้องมีครบทั้งคู่ · ใบที่ไม่มีกุญแจคู่กันทำให้เดมอนสตาร์ตไม่ขึ้นทั้งตัว
        return $cert !== '' && $key !== ''
            ? ['cert' => $cert, 'key' => $key]
            : ['cert' => self::DEFAULT_CERT, 'key' => self::DEFAULT_KEY];
    }

    /**
     * หาใบที่ครอบคลุมชื่อนี้ดีที่สุดบนเครื่อง
     *
     * ค้นทั้งใบของ Let's Encrypt และใบที่ panel เซ็นเอง — ใบที่เซ็นเองไม่ได้ทำให้
     * โปรแกรมเมลเลิกเตือน แต่ยังดีกว่าใบ snakeoil ของดิสโทรตรงที่อย่างน้อยชื่อในใบ
     * ตรงกับชื่อที่เซิร์ฟเวอร์ประกาศ · ใบจริงชนะใบที่เซ็นเองเสมอ
     *
     * @return array{cert:string,key:string,source:string,name:string,expires_at:int,days_left:int,status:string}|null
     */
    public function locate(Executor $executor, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === '') {
            return null;
        }

        $best = null;

        foreach ([CertbotManager::LIVE_DIR => 'letsencrypt', CertbotManager::SELF_SIGNED_DIR => 'self-signed'] as $dir => $source) {
            foreach ($this->certificateDirs($executor, $dir) as $name) {
                $cert = $dir . '/' . $name . '/fullchain.pem';
                $key = $dir . '/' . $name . '/privkey.pem';

                if (!$executor->exists($executor->path($cert)) || !$executor->exists($executor->path($key))) {
                    continue;
                }

                $info = $this->certbot->inspectFile($executor, $cert);

                if (!self::covers((array) ($info['domains'] ?? []), $hostname)) {
                    continue;
                }

                $candidate = [
                    'cert' => $cert,
                    'key' => $key,
                    'source' => $source,
                    'name' => $name,
                    'expires_at' => (int) ($info['expires_at'] ?? 0),
                    'days_left' => (int) ($info['days_left'] ?? 0),
                    'status' => (string) ($info['status'] ?? 'invalid'),
                ];

                if ($best === null || self::better($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * ชื่อใบทั้งหมดในไดเรกทอรีหนึ่ง — ไม่มีไดเรกทอรีก็คือยังไม่มีใบสักใบ ไม่ใช่ข้อผิดพลาด
     *
     * @return list<string>
     */
    private function certificateDirs(Executor $executor, string $dir): array
    {
        $resolved = $executor->path($dir);

        if (!$executor->exists($resolved)) {
            return [];
        }

        $names = [];

        foreach ($executor->listDirectory($resolved) as $entry) {
            if ($entry['type'] === 'dir' && $entry['name'] !== '.' && $entry['name'] !== '..') {
                $names[] = (string) $entry['name'];
            }
        }

        return $names;
    }

    /**
     * ใบนี้ครอบคลุมชื่อนี้ไหม
     *
     * รองรับ wildcard เพราะใบ `*.example.com` ที่ผู้ดูแลขอไว้สำหรับเว็บครอบคลุม
     * `mail.example.com` อยู่แล้ว — การไม่รู้จักมันแปลว่าบังคับให้ไปขอใบซ้ำโดยไม่จำเป็น
     *
     * @param array<int,mixed> $domains
     */
    public static function covers(array $domains, string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            if ($domain === $hostname) {
                return true;
            }

            // `*.example.com` ครอบคลุมได้ชั้นเดียว — `a.b.example.com` ไม่นับ
            // ตามกฎของ RFC 6125 ซึ่งเป็นสิ่งที่โปรแกรมเมลบังคับใช้จริง
            if (str_starts_with($domain, '*.')
                && str_ends_with($hostname, substr($domain, 1))
                && substr_count($hostname, '.') === substr_count($domain, '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * ใบไหนดีกว่ากัน — ใบจริงชนะใบที่เซ็นเอง แล้วค่อยดูว่าใบไหนอยู่ได้นานกว่า
     *
     * @param array{source:string,expires_at:int} $candidate
     * @param array{source:string,expires_at:int} $current
     */
    private static function better(array $candidate, array $current): bool
    {
        if ($candidate['source'] !== $current['source']) {
            return $candidate['source'] === 'letsencrypt';
        }

        return $candidate['expires_at'] > $current['expires_at'];
    }

    /**
     * ใบเปลี่ยนไปหลังจากที่เราบอกเดมอนครั้งล่าสุดหรือยัง
     *
     * เทียบเวลาแก้ไขของไฟล์ใบกับไฟล์ตั้งค่าที่ panel เขียนเอง — คำถามที่ถูกต้องคือ
     * "ใบเปลี่ยนหลังจากที่เราบอกไปแล้วหรือเปล่า" ซึ่งไฟล์ทั้งสองตอบได้โดยไม่ต้องเก็บ
     * สถานะเพิ่มที่ไหนอีก · ไม่มีไฟล์ตั้งค่า = ยังไม่เคยบอกใคร ถือว่าเปลี่ยน
     *
     * ใบของ Let's Encrypt เป็น symlink ที่ถูกสร้างใหม่ทุกครั้งที่ต่ออายุ จึงตาม
     * เส้นทางจริงไปดูเวลาของไฟล์ในคลัง ไม่ใช่ของลิงก์
     */
    public function changedSince(Executor $executor, string $certPath, string $configPath): bool
    {
        $config = $executor->stat($executor->path($configPath));

        if ($config === null) {
            return true;
        }

        $resolved = $executor->realPath($executor->path($certPath)) ?? $executor->path($certPath);
        $cert = $executor->stat($resolved);

        return $cert === null || $cert['mtime'] > $config['mtime'];
    }
}
