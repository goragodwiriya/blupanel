<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\AlertRules;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\ServiceCatalog;

/**
 * ตรวจเกณฑ์เตือนทั้งหมดแล้วแจ้งเฉพาะสิ่งที่เปลี่ยนแปลง — PLAN-V2 เฟส E6
 *
 * ตรวจสี่เรื่องที่ทำให้เครื่องโฮสติ้งล่มจริงเรียงตามความถี่ที่เกิด:
 *   1. **ดิสก์เต็ม** — MariaDB เขียนไม่ได้ ทุกเว็บล่มพร้อมกัน
 *   2. **หน่วยความจำ/load** — เว็บช้าจนหมดเวลาก่อนตอบ
 *   3. **บริการสำคัญหยุด** — Apache/PHP-FPM/MariaDB ตายแล้วไม่มีใครรู้จนลูกค้าโทรมา
 *   4. **ใบรับรองใกล้หมดอายุ** — เบราว์เซอร์ขึ้นหน้าเตือนสีแดงเต็มจอ
 *
 * **การตัดสินว่า "ควรส่งไหม" อยู่ที่ {@see AlertRules} ทั้งหมด** — ที่นี่แค่วัดค่าแล้วส่งต่อ
 * · แยกกันเพราะกฎการกันสแปม (แจ้งตอนเข้าสู่สถานะ · แจ้งซ้ำเมื่อแย่ลง · เงียบระหว่างนั้น)
 * เป็นตรรกะที่ต้องทดสอบด้วยการเดินเวลา ซึ่งทำไม่ได้ถ้าผูกอยู่กับการอ่านค่าจากเครื่องจริง
 *
 * ทำเครื่องหมายว่า **อ่านอย่างเดียว** เหมือน `disk.usage`/`metrics.record` — ไม่เปลี่ยนอะไร
 * บนเครื่อง เขียนแค่ตารางสถานะของ panel เอง และงานที่รันทุก 5 นาทีต้องไม่เติม audit log
 */
final class AlertCheck implements Capability
{
    public static function name(): string
    {
        return 'alert.check';
    }

    public function permission(): string
    {
        return 'dashboard.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ตรวจเกณฑ์เตือนของเครื่องและแจ้งเมื่อผิดปกติ';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $rules = new AlertRules($context->db);
        // ส่ง executor เข้าไปด้วยเพื่อให้แจ้งเตือนทางอีเมลได้ (ต้องเรียก sendmail)
        $notifier = new Notifier($context->db, $executor);

        $checked = [];
        $sent = 0;

        foreach ($this->collect($executor, $context) as $alert) {
            $decision = $rules->evaluate($alert['key'], $alert['level'], $alert['value']);

            $checked[] = [
                'key' => $alert['key'],
                'level' => $alert['level'] ?? 'ok',
                'value' => $alert['value'],
                'notified' => $decision['notify'],
                'reason' => $decision['reason'],
            ];

            if (!$decision['notify']) {
                continue;
            }

            $recovered = $decision['reason'] === 'recovered';

            $sent += $notifier->send(
                'alert',
                ($recovered ? 'กลับสู่ปกติ: ' : '') . $alert['title'],
                $recovered ? $alert['recovery'] : $alert['body'],
                $recovered ? 'ok' : ($alert['level'] === 'critical' ? 'danger' : 'warn'),
            ) ? 1 : 0;
        }

        /*
         * เกณฑ์ที่รอบนี้ไม่ได้ตรวจแล้ว ต้องหายไปจากรายการด้วย
         *
         * `collect()` ข้ามบริการที่ไม่ได้ติดตั้งบนเครื่องนี้ (ถูกต้อง — ไม่ใช่ความผิดปกติ)
         * แต่ถ้าบริการนั้นเคยหยุดทำงานตอนที่ยังลงอยู่ แถวของมันจะค้างในตารางตลอดไป
         * เพราะไม่มีใครประเมินคีย์นั้นอีกเลย · ผู้ดูแลเห็น "มีปัญหาค้างอยู่" ที่กดยังไง
         * ก็ไม่หาย แล้วเลิกเชื่อส่วนนี้ทั้งส่วน — อันตรายกว่าไม่มีมันเลย
         */
        $forgotten = $rules->forgetOthers(array_column($checked, 'key'));

        return [
            'checked' => count($checked),
            'notified' => $sent,
            'forgotten' => $forgotten,
            'alerts' => $checked,
            'channels' => $notifier->activeChannels(),
            'message' => sprintf('ตรวจเกณฑ์เตือน %d รายการ · ส่งแจ้งเตือน %d ครั้ง', count($checked), $sent),
        ];
    }

    /**
     * วัดค่าทุกเกณฑ์แล้วคืนรายการที่ต้องให้ `AlertRules` ตัดสิน
     *
     * คืน**ทุกเกณฑ์รวมถึงที่ปกติ** (level = null) โดยตั้งใจ — `AlertRules` ต้องรู้ว่าเกณฑ์
     * ที่เคยผิดปกติกลับมาปกติแล้ว ถึงจะส่งข้อความ "หายแล้ว" และล้างสถานะได้
     *
     * @return list<array{key:string,level:string|null,value:float,title:string,body:string,recovery:string}>
     */
    private function collect(Executor $executor, Context $context): array
    {
        $alerts = [];
        $metrics = (new SystemMetrics())->run([], $executor, $context);

        // --- ทรัพยากรที่วัดเป็นเปอร์เซ็นต์ ---
        foreach (AlertRules::THRESHOLDS as $type => [$warning, , $label]) {
            $percent = (float) ($metrics[$type]['percent'] ?? 0);
            $used = (int) ($metrics[$type]['used'] ?? 0);
            $total = (int) ($metrics[$type]['total'] ?? 0);

            $alerts[] = [
                'key' => $type,
                'level' => AlertRules::levelForPercent($type, $percent),
                'value' => $percent,
                'title' => sprintf('%sใช้ไป %.1f%%', $label, $percent),
                'body' => sprintf(
                    "%s: %s จาก %s (%.1f%%)\nเกณฑ์เตือนที่ %.0f%%",
                    $label,
                    $this->bytes($used),
                    $this->bytes($total),
                    $percent,
                    $warning,
                ),
                'recovery' => sprintf('%sกลับมาอยู่ที่ %.1f%% แล้ว', $label, $percent),
            ];
        }

        // --- load average ต่อคอร์ ---
        $load1 = (float) ($metrics['load'][1] ?? 0);
        $cores = max(1, (int) ($metrics['cores'] ?? 1));

        $alerts[] = [
            'key' => 'load',
            'level' => AlertRules::levelForLoad($load1, $cores),
            'value' => $load1,
            'title' => sprintf('โหลดเฉลี่ย %.2f (%d คอร์)', $load1, $cores),
            'body' => sprintf(
                "โหลดเฉลี่ย 1 นาที: %.2f บนเครื่อง %d คอร์ (%.2f ต่อคอร์)\n"
                . 'เกินหนึ่งต่อคอร์แปลว่ามีงานรอคิวอยู่จริง',
                $load1,
                $cores,
                $load1 / $cores,
            ),
            'recovery' => sprintf('โหลดกลับมาอยู่ที่ %.2f แล้ว', $load1),
        ];

        // --- บริการสำคัญที่หยุดทำงาน ---
        //
        // บริการบางชนิด**ใช้ทีละตัว**: เครื่องหนึ่งเสิร์ฟเว็บด้วย Apache หรือ Nginx
        // ไม่ใช่ทั้งคู่ · เช่นเดียวกับ MariaDB กับ MySQL ที่ใช้พอร์ตเดียวกัน
        //
        // คำถามที่ต้องเตือนจริงคือ **"ยังมีเว็บเซิร์ฟเวอร์ทำงานอยู่ไหม"** ไม่ใช่
        // "nginx ทำงานอยู่ไหม" — เครื่องนี้เป็นตัวอย่าง: nginx ถูกติดตั้งไว้และ enabled
        // แต่สตาร์ตไม่ขึ้นเพราะพอร์ต 80 ถูก Apache ใช้อยู่ ซึ่งเป็น**สภาพปกติ**
        // ของเครื่อง ไม่ใช่เหตุที่ต้องปลุกใคร · ถ้าเตือนทีละตัวจะเตือนทุก 6 ชั่วโมงตลอดไป
        //
        // ตรงข้ามกับ php-fpm ที่แต่ละเวอร์ชันแยกกันจริง — เว็บที่ตั้งไว้ใช้ 8.4
        // ล่มทันทีที่ 8.4 ตาย ไม่ว่าเวอร์ชันอื่นจะยังทำงานอยู่หรือไม่
        $exclusive = [ServiceCatalog::KIND_WEBSERVER, ServiceCatalog::KIND_DATABASE];
        $groupAlive = [];       // ชนิด → มีอย่างน้อยหนึ่งตัวที่ทำงานอยู่หรือยัง
        $groupMembers = [];     // ชนิด → รายชื่อที่ติดตั้งจริงบนเครื่องนี้
        $probes = [];

        foreach (ServiceCatalog::all() as $unit => $meta) {
            if (($meta['critical'] ?? false) !== true) {
                continue;   // บริการที่ไม่สำคัญหยุดได้โดยไม่ต้องปลุกใครกลางดึก
            }

            $status = ServiceProbe::read($executor, $unit);

            // ไม่ได้ติดตั้งบนเครื่องนี้ = ไม่ใช่ความผิดปกติ (เช่น nginx บนเครื่องที่ใช้ Apache
            // หรือ PHP เวอร์ชันที่ ServiceCatalog รู้จักแต่ยังไม่ได้ลงบนเครื่องนี้)
            //
            // **ตัดสินจาก `status` ไม่ใช่ `installed` เพียงอย่างเดียว** — `probeFallback()`
            // ของ ServiceProbe คืน `installed => true` แบบเหมารวมเมื่อมันเดาสถานะไม่ออก
            // ค่านั้นจึงเชื่อไม่ได้ · ส่วน `status` ผ่าน `statusOf()` ที่คำนวณจาก LoadState จริง
            //
            // เคยพลาดมาแล้ว: รอบแรกกรองด้วยคีย์ `load` ซึ่ง ServiceProbe **ไม่เคยคืน**
            // เงื่อนไขจึงไม่มีทางเป็นจริง แล้วระบบยิงแจ้งเตือนรวดเดียว 6 ข้อความเรื่อง
            // php-fpm เวอร์ชันที่ไม่ได้ลงไว้ — คือสแปมแบบที่ AlertRules ถูกเขียนมาเพื่อกัน
            if (($status['status'] ?? '') === 'not_installed' || ($status['installed'] ?? true) === false) {
                continue;
            }

            $running = (bool) ($status['running'] ?? false);
            $kind = (string) ($meta['kind'] ?? '');

            if (in_array($kind, $exclusive, true)) {
                $groupAlive[$kind] = ($groupAlive[$kind] ?? false) || $running;
                $groupMembers[$kind][] = $meta['label'] ?? $unit;
                $probes[$kind][] = $unit;

                continue;   // ตัดสินทั้งกลุ่มทีเดียวหลังวนครบ
            }

            $alerts[] = [
                'key' => 'service:' . $unit,
                'level' => $running ? null : 'critical',
                'value' => $running ? 1.0 : 0.0,
                'title' => sprintf('บริการ %s หยุดทำงาน', $meta['label'] ?? $unit),
                'body' => sprintf(
                    "บริการ %s (%s) ไม่ได้ทำงานอยู่\nสั่งเริ่มใหม่ได้ที่หน้า \"บริการ\" ของ panel",
                    $meta['label'] ?? $unit,
                    $unit,
                ),
                'recovery' => sprintf('บริการ %s กลับมาทำงานแล้ว', $meta['label'] ?? $unit),
            ];
        }

        // ชนิดที่ใช้ทีละตัว — เตือนเมื่อ**ไม่เหลือตัวไหนทำงานเลย** เพราะนั่นคือจุดที่
        // เว็บล่มจริง · คีย์เป็นชื่อชนิด ไม่ใช่ชื่อ unit จึงไม่มีทางเตือนซ้ำหลายใบต่อเรื่องเดียว
        foreach ($groupAlive as $kind => $alive) {
            $label = ServiceCatalog::KIND_WEBSERVER === $kind ? 'เว็บเซิร์ฟเวอร์' : 'ฐานข้อมูล';
            $members = implode(' หรือ ', array_unique($groupMembers[$kind]));

            $alerts[] = [
                'key' => 'service-kind:' . $kind,
                'level' => $alive ? null : 'critical',
                'value' => $alive ? 1.0 : 0.0,
                'title' => sprintf('ไม่มี%sทำงานอยู่เลย', $label),
                'body' => sprintf(
                    "ไม่มี%sตัวไหนทำงานอยู่บนเครื่องนี้ (ตรวจแล้ว: %s)\n"
                    . "เว็บไซต์ทุกเว็บบนเครื่องนี้เข้าไม่ได้ตอนนี้\n"
                    . 'สั่งเริ่มใหม่ได้ที่หน้า "บริการ" ของ panel',
                    $label,
                    $members,
                ),
                'recovery' => sprintf('%sกลับมาทำงานแล้ว', $label),
            ];
        }

        // --- ใบรับรองที่ใกล้หมดอายุ ---
        $now = time();
        $certificates = $context->db->all(
            "SELECT domain, not_after FROM certificates WHERE not_after IS NOT NULL AND status != 'pending'",
        );

        foreach ($certificates as $certificate) {
            $daysLeft = (int) floor(((int) $certificate['not_after'] - $now) / 86400);

            $alerts[] = [
                'key' => 'cert:' . $certificate['domain'],
                'level' => AlertRules::levelForCertDays($daysLeft),
                'value' => (float) $daysLeft,
                'title' => sprintf('ใบรับรองของ %s เหลือ %d วัน', $certificate['domain'], $daysLeft),
                'body' => sprintf(
                    "ใบรับรอง SSL ของ %s จะหมดอายุใน %d วัน (%s)\n"
                    . 'ปกติ certbot ต่ออายุให้เองที่ 30 วัน — ถ้าเหลือน้อยกว่านี้แปลว่าการต่ออายุอัตโนมัติมีปัญหา',
                    $certificate['domain'],
                    $daysLeft,
                    date('d/m/Y', (int) $certificate['not_after']),
                ),
                'recovery' => sprintf('ใบรับรองของ %s ถูกต่ออายุแล้ว (เหลือ %d วัน)', $certificate['domain'], $daysLeft),
            ];
        }

        return $alerts;
    }

    /** ขนาดที่คนอ่านได้ — ข้อความแจ้งเตือนต้องอ่านจากมือถือแล้วเข้าใจทันที */
    private function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value = (int) ($value / 1024);
            $index++;
        }

        return $value . ' ' . $units[$index];
    }
}
