<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\Site;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Support\Validator;

/**
 * ติดตั้งใบรับรอง — Let's Encrypt หรือใบที่เซ็นเอง
 *
 * ไม่เปิด SSL ให้อัตโนมัติหลังขอใบสำเร็จ แยกเป็นสองขั้นโดยเจตนา:
 * การขอใบอาจสำเร็จแต่ใบครอบคลุมโดเมนไม่ครบ หรือผู้ดูแลอาจยังไม่พร้อมสลับ
 * ให้เห็นผลก่อนแล้วค่อยกดเปิดใช้งาน ปลอดภัยกว่าสลับให้ทันทีแล้วเว็บล่ม
 */
final class SslIssue extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.issue';
    }

    public function permission(): string
    {
        return 'ssl.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ขอใบรับรอง SSL ให้เว็บไซต์';
    }

    public function validate(array $args): array
    {
        $method = Validator::requireEnum($args, 'method', ['letsencrypt', 'self-signed']);
        $out = [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'method' => $method,
            'staging' => (bool) ($args['staging'] ?? false),
            'email' => '',
        ];

        // อีเมลถูกตรวจตอน run() ไม่ใช่ตรงนี้ — ค่าที่ผู้เรียกไม่ได้ส่งมาจะถูกเติมจาก
        // เจ้าของเว็บหรือค่าตั้งของระบบก่อน (ดู resolveEmail) · ถ้าตรวจที่นี่จะปฏิเสธ
        // ตั้งแต่ยังไม่ได้ลองหาค่าที่มีอยู่แล้วในระบบ
        $out['email'] = trim((string) ($args['email'] ?? ''));

        return $out;
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);

        if (!$site->isActive()) {
            throw new ValidationError('เว็บไซต์ถูกระงับอยู่ — ยกเลิกการระงับก่อนจึงจะขอใบรับรองได้');
        }

        $certbot = $this->certbot();
        $domains = $this->domainsFor($site);

        if ($args['method'] === 'self-signed') {
            // ทุกโดเมนของเว็บ ไม่ใช่แค่โดเมนหลัก — ชุดเดียวกับที่ letsencrypt ขอไป
            $certbot->selfSign($executor, $site, $domains);

            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'method' => 'self-signed',
                'certificate' => $certbot->inspect($executor, $site),
                'message' => sprintf(
                    'สร้างใบรับรองที่เซ็นเองให้ %s แล้ว — เบราว์เซอร์จะขึ้นคำเตือนเสมอ '
                    . 'เหมาะกับการทดสอบหรือเว็บภายในเท่านั้น ไม่ควรใช้กับเว็บสาธารณะ',
                    $site->domain,
                ),
            ];
        }

        $certbot->issue($executor, $site, $domains, $this->resolveEmail($args['email'], $site, $context), $args['staging']);
        $certificate = $certbot->inspect($executor, $site);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'method' => 'letsencrypt',
            'domains' => $domains,
            'certificate' => $certificate,
            'message' => sprintf(
                'ขอใบรับรองให้ %s สำเร็จ (ครอบคลุม %d โดเมน หมดอายุใน %d วัน)%s — '
                . 'กด "เปิดใช้งาน HTTPS" เพื่อเริ่มใช้จริง',
                $site->domain,
                count($domains),
                $certificate['days_left'],
                $args['staging'] ? ' [ใบทดสอบ staging เบราว์เซอร์ไม่เชื่อถือ]' : '',
            ),
        ];
    }

    /**
     * หาอีเมลติดต่อสำหรับ Let's Encrypt ตามลำดับความเหมาะสม
     *
     * **ทำไมต้องเติมให้ ไม่ใช่บังคับให้ส่งมา:** หน้ารายการใบรับรองมีปุ่ม "ขอใบรับรอง"
     * เป็นปุ่มในแถวตาราง ซึ่งไม่มีที่ให้กรอกอะไร · ถ้าบังคับให้ส่งอีเมล ปุ่มนั้นจะ
     * ล้มเหลวทุกครั้งด้วยข้อความ "อีเมลไม่ถูกต้อง" ที่ชี้ไปคนละทางกับต้นตอ
     * (ผู้ใช้ไม่ได้กรอกอะไรผิด — ไม่มีช่องให้กรอกตั้งแต่แรก) · เจอบนเครื่องจริง 2026-08-11
     *
     * ลำดับที่เลือก:
     *   1. ค่าที่ผู้เรียกส่งมา — API ที่ระบุเองต้องชนะเสมอ
     *   2. **อีเมลของเจ้าของเว็บ** — คนที่ควรได้รับคำเตือนว่าใบใกล้หมดอายุคือคนที่
     *      ดูแลเว็บนั้น ไม่ใช่ผู้ดูแลเครื่องที่บังเอิญกดปุ่ม
     *   3. `mail.from` ของระบบ — ที่พึ่งสุดท้ายเมื่อบัญชีลูกค้าไม่ได้กรอกอีเมลไว้
     */
    private function resolveEmail(string $requested, Site $site, Context $context): string
    {
        if ($requested !== '') {
            return CertbotManager::assertEmail($requested);
        }

        $candidates = [];

        if ($site->owner->userId > 0) {
            $candidates[] = (string) $context->db->value(
                'SELECT email FROM users WHERE id = :id',
                ['id' => $site->owner->userId],
                '',
            );
        }

        $candidates[] = (new SettingsRepository($context->db))->get('mail.from');

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '' && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL) !== false) {
                return trim($candidate);
            }
        }

        throw new ValidationError(
            "ไม่พบอีเมลติดต่อสำหรับ Let's Encrypt — ระบบต้องใช้อีเมลนี้แจ้งเตือนตอนใบใกล้หมดอายุ\n\n"
            . "แก้ได้สองทาง: กรอกอีเมลในบัญชีเจ้าของเว็บไซต์ "
            . 'หรือตั้ง "อีเมลผู้ส่ง" ในหน้าตั้งค่าของระบบ',
        );
    }
}
