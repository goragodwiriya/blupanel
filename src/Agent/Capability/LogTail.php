<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * อ่านบรรทัดท้ายของไฟล์ log — อ่านอย่างเดียว
 *
 * ความปลอดภัยที่สำคัญที่สุดของ capability นี้: ผู้ใช้ระบุ "คีย์" ไม่ใช่ "เส้นทางไฟล์"
 * เส้นทางจริงมาจาก LogCatalog ซึ่งเป็น allowlist ตายตัวในโค้ด
 * จึงไม่มีทางอ่านไฟล์นอกรายการได้เลย ไม่ว่าจะพยายาม traversal แบบไหน
 *
 * คีย์รูป `site:<id>:<ชนิด>` อ่าน log ของเว็บไซต์หนึ่งแห่ง เส้นทางมาจาก `Site` ซึ่ง
 * คำนวณจากบ้านของเจ้าของ ผู้เรียกจึงยังระบุเส้นทางเองไม่ได้อยู่ดี — สิ่งเดียวที่เขา
 * เลือกได้คือ **เลขเว็บ** ซึ่งถูกตรวจความเป็นเจ้าของซ้ำที่นี่อีกชั้น
 *
 * อ่านจากท้ายไฟล์ถอยขึ้นมา ไม่โหลดทั้งไฟล์เข้าหน่วยความจำ —
 * log ขนาดหลายกิกะไบต์จึงเปิดดูได้โดยไม่ทำให้เครื่องล่ม
 */
final class LogTail implements Capability
{
    private const DEFAULT_LINES = 200;
    private const MAX_LINES = 2000;
    private const CHUNK = 8192;

    /** อ่านย้อนหลังไม่เกินเท่านี้ กันกรณีไฟล์เป็นบรรทัดเดียวยาวมาก */
    private const MAX_SCAN_BYTES = 4_194_304;

    public static function name(): string
    {
        return 'system.logs_tail';
    }

    public function permission(): string
    {
        // permission ละเอียดต่อแหล่ง log ตรวจซ้ำใน validate() อีกชั้น
        return 'log.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านบรรทัดท้ายของไฟล์ log';
    }

    public function validate(array $args): array
    {
        $source = Validator::requireString($args, 'source', 32);

        // ตรวจได้แค่ "รูปทรงของคีย์" ที่นี่ — ชั้นนี้ไม่มีฐานข้อมูลให้ถามว่าเว็บมีจริงไหม
        // และใครเป็นเจ้าของ · สองข้อนั้นตรวจใน run() ซึ่งมี Context
        if (!LogCatalog::has($source) && LogCatalog::parseSiteKey($source) === null) {
            throw new ValidationError("ไม่รู้จักแหล่ง log: {$source}");
        }

        $lines = isset($args['lines'])
            ? Validator::requireInt($args, 'lines', 10, self::MAX_LINES)
            : self::DEFAULT_LINES;

        // คำค้นเป็นข้อความธรรมดา ไม่ใช่ regex — ผู้ใช้จึงทำให้ระบบค้างด้วย catastrophic
        // backtracking ไม่ได้ และไม่ต้องกังวลเรื่อง pattern ที่เป็นอันตราย
        $search = Validator::optionalString($args, 'search', max: 200);

        $level = Validator::optionalString($args, 'level', max: 16);
        if ($level !== '' && !in_array($level, LogCatalog::levels(), true)) {
            throw new ValidationError('ระดับ log ไม่ถูกต้อง');
        }

        return [
            'source' => $source,
            'lines' => $lines,
            'search' => $search,
            'level' => $level,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        ['label' => $label, 'format' => $format, 'path' => $path]
            = $this->resolve((string) $args['source'], $executor, $context);

        if (!$executor->exists($path)) {
            return [
                'source' => $args['source'],
                'label' => $label,
                'path' => $path,
                'exists' => false,
                'lines' => [],
                'total' => 0,
                'size_bytes' => 0,
                'message' => 'ยังไม่มีไฟล์ log นี้บนเครื่อง',
            ];
        }

        $raw = $this->tail($path, $args['lines']);
        $filtered = $this->filter($raw, $args['search'], $args['level']);

        return [
            'source' => $args['source'],
            'label' => $label,
            'path' => $path,
            'format' => $format,
            'exists' => true,
            'lines' => $filtered,
            'total' => count($filtered),
            'scanned' => count($raw),
            'size_bytes' => (int) (@filesize($path) ?: 0),
            'read_at' => time(),
        ];
    }

    /**
     * แปลงคีย์เป็นเส้นทางไฟล์จริง พร้อมตรวจสิทธิ์ของแหล่งนั้น
     *
     * ทางเดียวที่คีย์กลายเป็นเส้นทางได้ — ทุกกรณีจบที่ค่าคงที่ในโค้ดหรือที่เส้นทาง
     * ซึ่ง `Site` คำนวณจากโดเมนที่ผ่าน validator มาแล้ว ไม่มีสายไหนรับ path จากผู้เรียก
     *
     * @return array{label:string,format:string,path:string}
     */
    private function resolve(string $key, Executor $executor, Context $context): array
    {
        $siteRef = LogCatalog::parseSiteKey($key);

        if ($siteRef !== null) {
            return $this->siteLog($siteRef['site_id'], $siteRef['kind'], $executor, $context);
        }

        $source = LogCatalog::get($key);
        // ตรวจ permission ของแหล่ง log ที่ละเอียดกว่าระดับ capability
        // (audit log ต้องใช้ audit.view ไม่ใช่แค่ log.view)
        if (!$context->actor->can($source['permission'])) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์อ่าน log นี้');
        }

        // log ของ panel เองอยู่คนละที่ตาม layout จึงถามจาก Paths ไม่ใช่ใช้ค่าคงที่
        // ส่วน log ของระบบใช้เส้นทางจาก catalog แล้วให้ executor แมปตามโหมด
        return [
            'label' => $source['label'],
            'format' => $source['format'],
            'path' => isset($source['panel_log'])
                ? $context->config->paths->logFile($source['panel_log'])
                : $executor->path($source['path']),
        ];
    }

    /**
     * log ของเว็บไซต์หนึ่งแห่ง
     *
     * ตรวจความเป็นเจ้าของซ้ำที่ชั้นนี้แม้ web tier กรองรายการมาให้แล้ว — agent ต้อง
     * ไม่เชื่อผู้เรียก · ถ้าข้ามด่านนี้ได้ ผู้ดูแลเว็บไซต์คนหนึ่งจะเดาเลข id แล้วอ่าน
     * access log ของลูกค้ารายอื่นได้ทั้งเครื่อง ซึ่งมีทั้ง IP ผู้เข้าชมและ URL ที่เรียก
     *
     * @return array{label:string,format:string,path:string}
     */
    private function siteLog(int $siteId, string $kind, Executor $executor, Context $context): array
    {
        $actor = $context->actor;

        if (!$actor->can(LogCatalog::SITE_PERMISSION)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์อ่าน log นี้');
        }

        $sites = new SiteRepository($context->db);

        if ($actor->userId !== 0
            && !Permissions::seesAllSites($actor->role)
            && !$sites->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์อ่าน log ของเว็บไซต์นี้');
        }

        $site = $sites->load($siteId);

        if ($site === null) {
            throw new ValidationError('ไม่พบเว็บไซต์ที่ระบุ');
        }

        $meta = LogCatalog::siteKinds()[$kind];

        $path = match ($kind) {
            'access' => $site->accessLog(),
            'error' => $site->errorLog(),
            'php' => $site->phpErrorLog(),
            // ชนิดใหม่ใน siteKinds() ที่ยังไม่มีเส้นทางรองรับ — ล้มดังกว่าเงียบ
            default => throw new ValidationError("ยังไม่รองรับ log ชนิด {$kind}"),
        };

        return [
            'label' => $site->domain.' · '.$meta['label'],
            'format' => $meta['format'],
            // เส้นทางเป็นแบบ logical เสมอ — ให้ executor แมปเข้า prefix ของโหมด sandbox
            'path' => $executor->path($path),
        ];
    }

    /**
     * อ่าน n บรรทัดสุดท้ายโดยไล่จากท้ายไฟล์ถอยขึ้น
     *
     * @return list<string>
     */
    private function tail(string $path, int $wanted): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);
            if ($position === false || $position === 0) {
                return [];
            }

            $floor = max(0, $position - self::MAX_SCAN_BYTES);
            $buffer = '';
            $newlines = 0;

            while ($position > $floor && $newlines <= $wanted) {
                $read = (int) min(self::CHUNK, $position - $floor);
                $position -= $read;

                fseek($handle, $position, SEEK_SET);
                $chunk = (string) fread($handle, $read);

                $buffer = $chunk . $buffer;
                $newlines = substr_count($buffer, "\n");
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/\R/', rtrim($buffer, "\r\n")) ?: [];

        // ตัดบรรทัดแรกทิ้งถ้าอ่านมาไม่ครบบรรทัด (เริ่มกลางบรรทัดพอดี)
        if ($position > 0 && count($lines) > 1) {
            array_shift($lines);
        }

        return array_slice($lines, -$wanted);
    }

    /**
     * @param list<string> $lines
     * @return list<array{n:int,level:string,text:string}>
     */
    private function filter(array $lines, string $search, string $level): array
    {
        $out = [];
        $number = 0;

        foreach ($lines as $line) {
            $number++;

            if ($line === '') {
                continue;
            }

            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }

            $lineLevel = LogCatalog::levelOf($line);

            if ($level !== '' && $lineLevel !== $level) {
                continue;
            }

            $out[] = [
                'n' => $number,
                'level' => $lineLevel,
                // ตัดบรรทัดที่ยาวผิดปกติ กันหน้าเว็บค้างเพราะ log บรรทัดเดียวยาวเป็นเมกะไบต์
                'text' => mb_substr($line, 0, 2000),
            ];
        }

        return $out;
    }
}
