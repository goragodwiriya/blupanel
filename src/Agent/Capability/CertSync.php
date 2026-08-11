<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * เทียบตาราง certificates กับไฟล์ใบรับรองจริงบนดิสก์
 *
 * ทำไมต้องมีทั้งที่ `ssl.list` อ่านจากไฟล์ตรง ๆ อยู่แล้ว: หน้าจอ SSL อ่านของจริงเสมอก็จริง
 * แต่ส่วนที่ต้อง "รู้ล่วงหน้า" โดยไม่มีคนเปิดหน้าจอ — การแจ้งเตือนใบรับรองใกล้หมดอายุ
 * และแดชบอร์ด — อ่านจากตารางนี้ ถ้าไม่มีใคร sync ตารางจะค้างอยู่ที่ค่าตอนขอใบครั้งแรก
 * ตลอดไป แล้วใบที่ certbot ต่ออายุให้เอง (ซึ่งไม่ผ่าน panel เลย) จะไม่มีวันถูกบันทึก
 *
 * อ่านอย่างเดียวในความหมายของ agent: ไม่แตะเครื่อง เขียนแค่ตารางแคชของ panel เอง
 */
final class CertSync extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'cert.sync';
    }

    public function permission(): string
    {
        return 'ssl.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ปรับสถานะใบรับรองในฐานข้อมูลให้ตรงกับไฟล์จริง';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $certbot = $this->certbot();
        $repository = $this->repository($context);

        $synced = 0;
        $expiring = 0;
        $missing = 0;
        $seen = [];

        foreach ($context->db->all('SELECT id FROM sites ORDER BY id') as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            $certificate = $certbot->inspect($executor, $site);

            if ($certificate['status'] === 'none') {
                // ไม่มีใบก็ไม่ควรมีแถวค้างอยู่ ไม่งั้นหน้าจอจะเตือนเรื่องใบที่ถูกลบไปแล้ว
                $missing += $this->forget($context, $site->domain);

                continue;
            }

            $seen[] = $site->domain;
            $status = self::normalizeStatus((string) $certificate['status']);

            if (in_array($status, ['expiring', 'expired'], true)) {
                $expiring++;
            }

            $this->store($context, $site->domain, $status, $certificate);
            $synced++;
        }

        return [
            'synced' => $synced,
            'expiring' => $expiring,
            'removed' => $missing,
            'domains' => $seen,
            'message' => sprintf('ปรับสถานะใบรับรอง %d ใบ (ใกล้หมดอายุ %d)', $synced, $expiring),
        ];
    }

    /**
     * แปลงสถานะจากตัวอ่านไฟล์ให้อยู่ในค่าที่คอลัมน์ยอมรับ
     *
     * ต้องแปลงตรงนี้ ไม่ใช่ผ่อน CHECK constraint ของตาราง — constraint คือสิ่งที่กัน
     * ไม่ให้สถานะแปลก ๆ หลุดเข้าไปจนหน้าจอแสดงคำที่ไม่มีใครรู้ความหมาย
     */
    private static function normalizeStatus(string $status): string
    {
        return in_array($status, ['valid', 'expiring', 'expired'], true) ? $status : 'error';
    }

    /** @param array<string,mixed> $certificate */
    private function store(Context $context, string $domain, string $status, array $certificate): void
    {
        $existing = $context->db->first(
            'SELECT id FROM certificates WHERE domain = :domain',
            ['domain' => $domain],
        );

        $data = [
            'issuer' => mb_substr((string) $certificate['issuer'], 0, 190),
            'status' => $status,
            'not_after' => (int) $certificate['expires_at'],
            'auto_renew' => ($certificate['auto_renew'] ?? false) ? 1 : 0,
            'last_error' => null,
        ];

        if ($existing === null) {
            $context->db->insert('certificates', ['domain' => $domain] + $data);

            return;
        }

        $context->db->update('certificates', $data, ['id' => (int) $existing['id']]);
    }

    private function forget(Context $context, string $domain): int
    {
        return $context->db->run(
            'DELETE FROM certificates WHERE domain = :domain',
            ['domain' => $domain],
        )->rowCount();
    }
}
