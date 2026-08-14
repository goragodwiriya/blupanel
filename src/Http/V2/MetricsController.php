<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\AgentException;
use Phpcp\Domain\MetricsHistoryRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ตัวเลขทรัพยากรของเครื่อง — `/api/v2/metrics`
 *
 * `GET /metrics` อ่านค่าปัจจุบันครั้งเดียว · `GET /metrics/stream` เป็น SSE ที่ส่งค่าใหม่
 * ทุก 2 วินาที ซึ่งเป็น **ข้อยกเว้นที่สองของกฎ "ทุก endpoint ตอบ JSON"** ต่อจาก
 * `files/download` — SSE เป็น `text/event-stream` ตามมาตรฐานของมันเอง และเบราว์เซอร์
 * ต่อกลับให้อัตโนมัติเมื่อหลุด ซึ่งเป็นเหตุผลที่เลือกมันแทน WebSocket ตั้งแต่แรก
 * (แต่ละ event ที่ส่งออกไปยังเป็น JSON อยู่ดี)
 *
 * `GET /metrics/history` อ่านค่าย้อนหลังจากตาราง `metrics_history` (เฟส E6) — ไม่ผ่าน agent
 * เพราะเป็นการอ่านตารางของ panel เอง ไม่ได้แตะเครื่อง (แบบเดียวกับ audit log)
 */
final class MetricsController extends ApiController
{
    private const INTERVAL_SECONDS = 2;
    private const MAX_DURATION = 1800;     // 30 นาที แล้วให้เบราว์เซอร์ต่อใหม่เอง

    /**
     * ช่วงเวลาที่เลือกได้ => [ย้อนหลังกี่วินาที, หนึ่งจุดในกราฟกว้างกี่วินาที, รูปแบบป้าย]
     *
     * **จำนวนจุดถูกกำหนดที่นี่ ไม่ใช่ให้กราฟไปตัดเอง** — `GraphComponent` วาดแค่
     * `data.slice(0, maxDataPoints)` ซึ่งเป็น **จุดแรก ๆ ของชุด ไม่ใช่จุดล่าสุด** ·
     * ส่งค่ารายนาทีทั้ง 1,440 จุดของ 24 ชั่วโมงไป กราฟจึงวาดแค่ 20 นาทีแรกของชุด
     * แล้วค้างอยู่ตรงนั้นตลอด (ดูเหมือนกราฟ "ไม่ขยับ" ทั้งที่ข้อมูลสดมาครบ) และป้าย
     * ทุกช่วงก็หน้าตาเหมือนกันหมดเพราะเป็นนาทีแรก ๆ ของชุดเสมอ ไม่ว่าจะเลือกช่วงไหน
     *
     * ผลพลอยได้คือคำตอบเล็กลงจากหลักพันจุดเหลือหลักสิบ — แต่เหตุผลหลักคือ **หนึ่งจุด
     * ต่อหนึ่งช่วงเวลาที่อ่านออก** ซึ่งเป็นสิ่งเดียวที่ทำให้ตัวเลือกช่วงมีความหมาย
     *
     * @var array<string,array{0:int,1:int,2:string}>
     */
    private const RANGES = [
        '1h' => [3600, 300, 'H:i'],            // 12 จุด · ทุก 5 นาที
        '6h' => [21600, 1800, 'H:i'],          // 12 จุด · ทุกครึ่งชั่วโมง
        '24h' => [86400, 3600, 'H:i'],         // 24 จุด · ทุกชั่วโมง
        '7d' => [604800, 43200, 'j/n H:i'],    // 14 จุด · ทุกครึ่งวัน
        '30d' => [2592000, 86400, 'j/n'],      // 30 จุด · ทุกวัน
        '1y' => [31536000, 2592000, 'M'],      // 12 จุด · ทุก 30 วัน
    ];

    public function index(Request $request): Response
    {
        return $this->ok($this->agent()->data('system.metrics', [], $this->ctx->actor($request)));
    }

    /**
     * ค่าย้อนหลังสำหรับกราฟ — PLAN-V2 เฟส E6
     *
     * เลือกชั้นความละเอียดให้เองตามช่วงที่ขอ (นาที/ชั่วโมง/วัน) หน้าจอจึงส่งมาแค่ `range`
     * ไม่ต้องรู้ว่าข้อมูลถูกยุบชั้นยังไง — ถ้าให้หน้าจอเลือกเอง วันที่เปลี่ยนนโยบายการยุบ
     * จะต้องไล่แก้ทุกที่ที่เรียก
     */
    public function history(Request $request): Response
    {
        $range = (string) $request->get('range', '24h');

        if (!isset(self::RANGES[$range])) {
            return $this->problem(
                ApiProblem::ValidationError,
                $this->t('Unknown range') . ' — ' . $this->t('Allowed: ') . implode(', ', array_keys(self::RANGES)),
                ['range' => 'ค่าที่ส่งมาไม่อยู่ในรายการ'],
            );
        }

        [$seconds, $step, $format] = self::RANGES[$range];
        $bucket = MetricsHistoryRepository::bucketForRange($seconds);
        $since = time() - $seconds;

        $repository = new MetricsHistoryRepository($this->app->db());
        $rows = $repository->summarise($repository->range($bucket, $since), $step);

        return $this->ok(
            $this->toSeries($rows, $format),
            [
                'range' => $range,
                'bucket' => $bucket,
                'since' => $since,
                // ความกว้างของหนึ่งจุด — หน้าจออธิบายให้ผู้ใช้ได้ว่ากำลังดูค่าเฉลี่ยของอะไร
                'step' => $step,
                'points' => count($rows),
                // หน้าจอใช้บอกผู้ใช้ว่าทำไมกราฟถึงว่าง แทนที่จะแสดงกล่องเปล่าเฉย ๆ
                'collecting' => $rows === [],
            ],
        );
    }

    /**
     * แปลงแถวจากฐานข้อมูลเป็นรูป `[{name, data:[{label, value}]}]`
     *
     * **นี่คือรูปที่ `GraphComponent` ของ Now.js อ่านได้ตรง ๆ ผ่าน `data-url`** — เทมเพลต
     * จึงประกาศกราฟด้วยแอตทริบิวต์อย่างเดียวโดยไม่ต้องเขียน JS แปลงข้อมูล ตามกฎของ
     * `js/pages.js` ที่ว่า "ห้ามมีอะไรที่ data-attribute ทำแทนได้"
     *
     * **ไม่ใช่การผูก REST เข้ากับ chart library ตัวใดตัวหนึ่ง** — `{name, data:[{label,value}]}`
     * คือรูป "ชุดข้อมูลหลายเส้นพร้อมป้ายกำกับ" แบบทั่วไป และเป็นสัญญาที่เฟรมเวิร์กของ
     * โปรเจกต์นี้ใช้อยู่แล้วทุกหน้า (ดู `api/v1/quarterly-sales` ของ adminframework)
     * ผู้เรียกที่ต้องการตัวเลขดิบยังอ่าน `value` ได้ตรง ๆ และ `meta` บอกบริบทครบ
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{name:string,data:list<array{label:string,value:float}>}>
     */
    private function toSeries(array $rows, string $format): array
    {
        // รูปแบบป้ายมากับช่วงที่เลือก ไม่ใช่กับชั้นที่เก็บ — ชั้นเดียวกันใช้ได้หลายช่วง
        // (7 วันกับ 30 วันอ่านจากชั้นชั่วโมงเหมือนกัน แต่ป้ายต้องคนละแบบ)
        $labels = array_map(
            static fn (array $row): string => date($format, (int) $row['bucket_at']),
            $rows,
        );

        $series = [];

        foreach ([
            'CPU' => 'cpu_percent',
            'Memory' => 'memory_percent',
            'ดิสก์' => 'disk_percent',
        ] as $name => $column) {
            $points = [];

            foreach ($rows as $index => $row) {
                $points[] = ['label' => $labels[$index], 'value' => round((float) $row[$column], 1)];
            }

            $series[] = ['name' => $name, 'data' => $points];
        }

        return $series;
    }

    /**
     * สตรีมค่าสด — ย้ายมาจาก `Controller\Api\StreamController` ตอนลบ UI แบบ HTML
     *
     * เรื่องที่พลาดง่ายและจัดการไว้แล้วทั้งหมด: ปิด output buffer ก่อนเริ่ม · ตรวจว่า
     * เบราว์เซอร์ยังอยู่ทุกรอบ (ไม่งั้นการเชื่อมต่อที่ค้างจะกินสล็อต PHP-FPM ไว้ตลอด)
     * · หยุดเองเมื่อ agent ล่มติดกันสามรอบ · บังคับปิดที่ 30 นาทีแล้วให้เบราว์เซอร์
     * ต่อกลับเอง ซึ่งเป็นความสามารถที่ทำให้เลือก SSE แทน WebSocket ตั้งแต่แรก
     */
    public function stream(Request $request): Response
    {
        $actor = $this->ctx->actor($request);
        $agent = $this->agent();

        // ส่ง header เองเพราะต้องเริ่ม stream ก่อนจบ request cycle
        // จึงใช้ Response ปกติไม่ได้
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');    // ปิด buffer ของ nginx ถ้ามี proxy คั่นอยู่
        header('Connection: keep-alive');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // เบราว์เซอร์ใช้ค่านี้ตัดสินใจว่าจะต่อกลับเมื่อไรถ้าหลุด
        echo 'retry: 5000' . "\n\n";
        flush();

        $deadline = time() + self::MAX_DURATION;
        $failures = 0;

        while (time() < $deadline) {
            // ฝั่งเบราว์เซอร์ปิดแท็บไปแล้ว — ปล่อยสล็อต FPM คืนทันที
            if (connection_aborted() === 1) {
                break;
            }

            try {
                $data = $agent->data('system.metrics', [], $actor);
                $failures = 0;

                $this->send('metrics', $data);
            } catch (AgentException $e) {
                $failures++;
                $this->send('error', ['message' => $e->getMessage()]);

                // agent ล่มติดต่อกันหลายรอบ — หยุด stream ให้เบราว์เซอร์ต่อใหม่เอง
                // ดีกว่าวนยิง socket ที่ตายแล้วทุก 2 วินาทีไม่รู้จบ
                if ($failures >= 3) {
                    break;
                }
            }

            sleep(self::INTERVAL_SECONDS);
        }

        $this->send('bye', ['reason' => 'หมดเวลาเชื่อมต่อ กรุณาโหลดหน้าใหม่']);

        // ตอบกลับเป็น response ว่างเพราะเนื้อหาถูกส่งไปหมดแล้วระหว่าง stream
        return Response::noContent();
    }

    /** @param array<string,mixed> $data */
    private function send(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo 'event: ' . $event . "\n";
        echo 'data: ' . ($json === false ? '{}' : $json) . "\n\n";

        flush();
    }
}
