<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\LogCatalog;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * Log viewer — `/api/v2/logs`
 *
 * ผู้ใช้เลือก **"แหล่ง" จากรายการ** ไม่ได้ระบุเส้นทางไฟล์เอง — เส้นทางจริงอยู่ใน
 * `LogCatalog` ฝั่งเซิร์ฟเวอร์เท่านั้น · นี่คือเหตุผลที่ API นี้อ่านไฟล์ใดก็ได้บนเครื่อง
 * ไม่ได้แม้จะพยายาม: ค่าที่ส่งมาเป็นคีย์ ไม่ใช่เส้นทาง
 *
 * รายการแหล่งยังกรองตาม role อีกชั้น — ผู้ดูแลเว็บไซต์เห็นเฉพาะ log ของเว็บตัวเอง
 * ไม่เห็น log ของระบบหรือของ panel
 */
final class LogsController extends ApiController
{
    private const DEFAULT_LINES = 200;
    private const MIN_LINES = 50;
    private const MAX_LINES = 2000;

    /** แหล่ง log ที่ผู้เรียกอ่านได้ */
    public function sources(Request $request): Response
    {
        $sources = [];

        foreach (LogCatalog::forRole($this->ctx->role()) as $key => $source) {
            $sources[] = [
                'key' => $key,
                'label' => $source['label'] ?? $key,
                'group' => $source['group'] ?? '',
                'format' => $source['format'] ?? ''
            ];
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
        $available = LogCatalog::forRole($this->ctx->role());
        $source = $request->get('source');

        if ($source === '' && $available !== []) {
            $source = (string) array_key_first($available);
        }

        if (!isset($available[$source])) {
            // 403 ไม่ใช่ 404 — คีย์ของแหล่ง log ไม่ใช่ความลับ (อยู่ในเอกสาร)
            // สิ่งที่ต่างกันคือสิทธิ์ ผู้ดูแลจึงควรรู้ว่าต้องไปแก้ที่สิทธิ์ ไม่ใช่ที่ URL
            return $this->problem(ApiProblem::Forbidden, 'ไม่มีสิทธิ์อ่าน log นี้ หรือไม่รู้จักแหล่ง log');
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
}