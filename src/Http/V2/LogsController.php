<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * Log viewer — `/api/v2/logs`
 *
 * ผู้ใช้เลือก **"แหล่ง" จากรายการ** ไม่ได้ระบุเส้นทางไฟล์เอง — เส้นทางจริงอยู่ใน
 * `LogCatalog` ฝั่งเซิร์ฟเวอร์เท่านั้น · นี่คือเหตุผลที่ API นี้อ่านไฟล์ใดก็ได้บนเครื่อง
 * ไม่ได้แม้จะพยายาม: ค่าที่ส่งมาเป็นคีย์ ไม่ใช่เส้นทาง
 *
 * รายการมีทั้ง log ระดับเครื่องและ log ของแต่ละเว็บไซต์ · ทั้งสองชุดถูกกรองด้วย
 * สิทธิ์และความเป็นเจ้าของก่อนถึงหน้าจอ แล้วถูกตรวจซ้ำอีกครั้งที่ agent
 */
final class LogsController extends ApiController
{
    private const DEFAULT_LINES = 50;
    private const MIN_LINES = 50;
    private const MAX_LINES = 2000;

    /** แหล่ง log ที่ผู้เรียกอ่านได้ */
    public function sources(Request $request): Response
    {
        $sources = [];

        foreach ($this->availableSources() as $key => $source) {
            $sources[] = ['key' => $key] + $source;
        }

        return $this->ok($sources, [
            'levels' => LogCatalog::levels(),
            'default_lines' => self::DEFAULT_LINES,
            'max_lines' => self::MAX_LINES
        ]);
    }

    /** อ่านบรรทัดท้าย ๆ ของแหล่งที่เลือก พร้อมกรองข้อความและระดับ */
    public function tail(Request $request): Response
    {
        $available = $this->availableSources();
        $source = $request->get('source');

        if ($source === '' && $available !== []) {
            $source = (string) array_key_first($available);
        }

        if (!isset($available[$source])) {
            // 403 ไม่ใช่ 404 — คีย์ของแหล่ง log ไม่ใช่ความลับ (อยู่ในเอกสาร)
            // สิ่งที่ต่างกันคือสิทธิ์ ผู้ดูแลจึงควรรู้ว่าต้องไปแก้ที่สิทธิ์ ไม่ใช่ที่ URL
            return $this->problem(ApiProblem::Forbidden, 'Unknown log source, or you may not read it');
        }

        $lines = max(self::MIN_LINES, min(self::MAX_LINES, $request->queryInt('per_page', self::DEFAULT_LINES)));

        $data = $this->agent()->data('system.logs_tail', [
            'source' => $source,
            'lines' => $lines,
            'search' => $this->searchTerm($request),
            'level' => $request->get('level')
        ], $this->ctx->actor($request));

        // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${level_tone}` ได้ตรง ๆ
        $data['lines'] = array_map(static function (array $line): array {
            $level = (string) ($line['level'] ?? '');

            $line['level_tone'] = match ($level) {
                'error' => 'danger',
                'warn' => 'warn',
                'ok' => 'ok',
                default => 'muted',
            };
            $line['level_label'] = $level === '' ? 'line' : $level;

            return $line;
        }, $data['lines'] ?? []);

        return $this->ok($data['lines'] ?? [], [
            'source' => $source,
            'page' => 1,
            'per_page' => $lines,
            'total' => $data['total'] ?? 0,
            'total_pages' => 1
        ]);
    }

    /**
     * แหล่งทั้งหมดที่ผู้เรียกอ่านได้ — ระดับเครื่องก่อน แล้วตามด้วยรายเว็บ
     *
     * **เป็นทั้งรายการที่แสดงและ allowlist ตอนอ่าน** — สองอย่างนี้ต้องมาจากที่เดียว
     * ไม่งั้นวันหนึ่งจะมีแหล่งที่ไม่โผล่ในรายการแต่ยังเรียกตรง ๆ ได้อยู่
     *
     * ระดับเครื่องมาก่อนโดยตั้งใจ: `tail()` ใช้ตัวแรกเป็นค่าเริ่มต้นเมื่อไม่ได้ระบุแหล่ง
     * ซึ่งต้องเป็นค่าที่คงที่ ไม่ใช่เว็บของลูกค้ารายไหนก็ไม่รู้ที่บังเอิญมาเป็นตัวแรก
     *
     * @return array<string,array{label:string,group:string,format:string}>
     */
    private function availableSources(): array
    {
        $sources = [];

        foreach (LogCatalog::forRole($this->ctx->role()) as $key => $source) {
            $sources[$key] = [
                'label' => $source['label'] ?? $key,
                'group' => $source['group'] ?? '',
                'format' => $source['format'] ?? ''
            ];
        }

        if (!$this->ctx->can(LogCatalog::SITE_PERMISSION)) {
            return $sources;
        }

        // ผู้ที่ไม่ได้เห็นเว็บของทุกคนได้เฉพาะของตัวเอง — ตัวกรองเดียวกับที่ LogTail
        // ตรวจซ้ำฝั่ง agent · ที่นี่ทำเพื่อไม่ให้รายการโชว์สิ่งที่กดแล้วโดนปฏิเสธ
        $ownerId = Permissions::seesAllSites($this->ctx->role()) ? null : $this->ctx->userId();

        foreach ((new SiteRepository($this->app->db()))->listBrief($ownerId) as $site) {
            foreach (LogCatalog::siteKinds() as $kind => $meta) {
                $sources[LogCatalog::siteKey($site['id'], $kind)] = [
                    /*
                     * ชื่อเจ้าของอยู่ใน**ป้าย** ไม่ใช่แค่ใน `group` เพราะ `<select>` ของหน้า
                     * log ยังเป็นรายการแบน — ค่าใน `group` จึงไม่ถูกวาดออกมาเลย · รายการ
                     * เรียงตามเจ้าของอยู่แล้ว คำนำหน้าที่ซ้ำกันจึงกลายเป็นบล็อกที่มองเห็น
                     * ได้ ซึ่งเป็นสิ่งที่ใกล้เคียง optgroup ที่สุดเท่าที่ทำได้ตอนนี้
                     */
                    'label' => $site['owner'].' · '.$site['domain'].' · '.$meta['label'],
                    // เก็บไว้ให้หน้าจอที่จัดกลุ่มได้ใช้ — คำถามที่หน้านี้ต้องตอบคือ
                    // "เว็บของลูกค้ารายนี้เจออะไร" ไม่ใช่ "เครื่องทั้งเครื่องเจออะไร"
                    'group' => 'เว็บไซต์ของ '.$site['owner'],
                    'format' => $meta['format']
                ];
            }
        }

        return $sources;
    }
}