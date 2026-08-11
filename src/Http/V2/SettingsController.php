<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\Notifier;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ค่าตั้งของ panel — `/api/v2/settings`
 *
 * **ห้ามให้ชั้นเว็บเขียน `config.php` เด็ดขาด** (§7.1 ข้อ 3) — ไฟล์นั้นถูก `include`
 * ตอนบูต ช่องโหว่เดียวในหน้าตั้งค่าจึงเท่ากับรันโค้ดทันที · ค่าที่แก้ได้จากที่นี่
 * ทั้งหมดอยู่ในตาราง `settings` ผ่าน capability `settings.set` เท่านั้น
 *
 * ค่าที่เป็นความลับ (token ของบอท) ถูกปิดบังเป็น `********` ตั้งแต่ฝั่ง capability —
 * token ที่หลุดออกไปทางคำตอบแปลว่าใครก็ส่งข้อความในนามระบบได้ และมันจะติดอยู่ใน
 * แคชของเบราว์เซอร์กับประวัติของ proxy ไปอีกนาน · การส่งค่าเดิม `********` กลับมา
 * ตอนบันทึกจะถูก capability มองข้าม ไม่ใช่เขียนทับ token จริงด้วยดอกจัน
 */
final class SettingsController extends ApiController
{
    public function show(Request $request): Response
    {
        $data = $this->agent()->data('settings.get', [], $this->ctx->actor($request));
        $notifier = new Notifier($this->app->db());

        return $this->ok($data, [
            'keys' => SettingsRepository::keys(),
            'events' => Notifier::EVENTS,
            'event_labels' => Notifier::LABELS,
            'notify_active' => $notifier->isActive(),
            // ช่องทางที่ "ตั้งค่าครบและเปิดอยู่" ไม่ใช่แค่ติ๊กสวิตช์ไว้ — หน้าจอใช้บอกความจริง
            // ว่าตอนนี้ข้อความจะออกไปทางไหนได้บ้าง (ดู Notifier::activeChannels)
            'notify_channels' => $notifier->activeChannels(),
        ]);
    }

    /**
     * แก้ค่าตั้งบางส่วน
     *
     * ส่งเฉพาะคีย์ที่ต้องการเปลี่ยน — ต่างจากฟอร์ม HTML เดิมที่ต้องส่งทุกคีย์ทุกครั้ง
     * เพราะ checkbox ที่ไม่ถูกติ๊กจะไม่ถูกส่งมาเลย · ที่นี่ค่า boolean ส่งมาเป็น
     * true/false ตรง ๆ ได้ ความกำกวมนั้นจึงหายไป
     */
    public function update(Request $request): Response
    {
        $known = SettingsRepository::keys();
        $args = [];

        foreach ($request->json() + $request->post as $key => $value) {
            if ($key === '_token' || !array_key_exists($key, $known)) {
                continue;
            }

            $args[$key] = $known[$key] === 'bool'
                ? (in_array($value, [true, 1, '1', 'true', 'on'], true) ? '1' : '0')
                : (string) $value;
        }

        if ($args === []) {
            return $this->problem(
                \Phpcp\Http\ApiProblem::ValidationError,
                'ต้องระบุค่าที่ต้องการเปลี่ยนอย่างน้อยหนึ่งค่า',
                ['keys' => 'ดูรายการคีย์ที่แก้ได้จาก GET /api/v2/settings'],
            );
        }

        $result = $this->agent()->data('settings.set', $args, $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'บันทึกค่าตั้งแล้ว'), '', is_array($result) ? $result : []);
    }

    /**
     * ส่งข้อความทดสอบไปยังช่องทางแจ้งเตือนช่องหนึ่ง
     *
     * `channel` ไม่ส่งมา = `telegram` เพื่อไม่ให้ผู้เรียกเดิมพัง (ตอนที่มีช่องทางเดียว)
     * · ทดสอบทีละช่องเพราะผู้ดูแลต้องรู้ว่าช่องไหนพัง ไม่ใช่รู้แค่ว่า "บางช่องพัง"
     */
    public function testNotification(Request $request): Response
    {
        $result = $this->agent()->data(
            'notify.test',
            ['channel' => $request->payloadString('channel')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'ส่งข้อความทดสอบแล้ว'), '', is_array($result) ? $result : []);
    }

    /**
     * ตั้งค่าเมลขาออก
     *
     * ขอบเขตที่จงใจจำกัดไว้: Postfix ที่ฟังเฉพาะ loopback สำหรับส่งเมลแจ้งเตือนออกเท่านั้น
     * ไม่ใช่ mail hosting เต็มรูปแบบ (§2.1) — การตั้ง relay ผิดจนกลายเป็น open relay
     * จะกระทบชื่อเสียง IP ของทุกเว็บไซต์บนเครื่องพร้อมกัน
     */
    public function applyMail(Request $request): Response
    {
        $result = $this->agent()->data(
            'mail.apply',
            ['hostname' => $request->payloadString('hostname')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'บันทึกค่าอีเมลแล้ว'), '', is_array($result) ? $result : []);
    }

    /** ส่งเมลทดสอบ */
    public function testMail(Request $request): Response
    {
        $result = $this->agent()->data(
            'mail.test',
            ['to' => $request->payloadString('to')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'ส่งอีเมลทดสอบแล้ว'), '', is_array($result) ? $result : []);
    }
}
