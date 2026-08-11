<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * ความสัมพันธ์ระหว่างบริการของเซิร์ฟเวอร์กับสิ่งที่ Hosting ใช้งานอยู่ — PROMPT.md
 *
 *   Nginx / Apache  → เว็บไซต์ที่ใช้งาน
 *   PHP-FPM 8.4     → เว็บไซต์ที่ตั้งค่าใช้เวอร์ชันนี้
 *   MariaDB         → ฐานข้อมูลที่ใช้งาน
 *
 * จุดสำคัญตาม "Important UX Rule": หน้านี้ "แสดง" ความสัมพันธ์เพื่อให้เห็นผลกระทบ
 * แต่ไม่ได้เปิดให้จัดการเว็บไซต์จากที่นี่ — สองชั้นยังแยกกันชัดเจน
 *
 * ดึงด้วย query ชุดเดียวต่อหนึ่งหน้า ไม่ใช่ query ต่อบริการ (กัน N+1)
 */
final class ServiceRelations
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * ความสัมพันธ์ของทุกบริการในครั้งเดียว
     *
     * @param list<string> $units
     * @return array<string,array{kind:string,label:string,items:list<array{name:string,detail:string,url:string}>,total:int}>
     */
    public function forUnits(array $units): array
    {
        $sites = $this->db->all(
            'SELECT id, name, primary_domain, php_version, status FROM sites ORDER BY primary_domain'
        );
        $databases = $this->db->all(
            'SELECT d.id, d.db_name, d.size_bytes, s.primary_domain
             FROM databases_ d LEFT JOIN sites s ON s.id = d.site_id
             ORDER BY d.db_name'
        );
        $crons = $this->db->all(
            'SELECT c.id, c.name, c.schedule, c.enabled, s.primary_domain
             FROM cron_jobs c LEFT JOIN sites s ON s.id = c.site_id
             ORDER BY c.name'
        );

        $result = [];

        foreach ($units as $unit) {
            $result[$unit] = match (ServiceCatalog::kind($unit)) {
                ServiceCatalog::KIND_WEBSERVER => $this->webserver($sites),
                ServiceCatalog::KIND_PHP => $this->php($sites, ServiceCatalog::phpVersionFromUnit($unit)),
                ServiceCatalog::KIND_DATABASE => $this->databases($databases),
                ServiceCatalog::KIND_SCHEDULER => $this->crons($crons),
                default => ['kind' => 'none', 'label' => '', 'items' => [], 'total' => 0],
            };
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $sites */
    private function webserver(array $sites): array
    {
        $items = [];

        foreach ($sites as $site) {
            if ($site['status'] !== 'active') {
                continue;
            }

            $items[] = [
                'name' => (string) $site['primary_domain'],
                'detail' => 'PHP ' . $site['php_version'],
                'url' => '/sites/' . $site['id'],
            ];
        }

        return ['kind' => 'sites', 'label' => 'เว็บไซต์ที่ใช้งาน', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $sites */
    private function php(array $sites, ?string $version): array
    {
        $items = [];

        foreach ($sites as $site) {
            if ($version === null || $site['php_version'] !== $version) {
                continue;
            }

            $items[] = [
                'name' => (string) $site['primary_domain'],
                'detail' => $site['status'] === 'active' ? 'ทำงานปกติ' : 'ถูกระงับ',
                'url' => '/sites/' . $site['id'],
            ];
        }

        return ['kind' => 'sites', 'label' => 'เว็บไซต์ที่ใช้งาน', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $databases */
    private function databases(array $databases): array
    {
        $items = [];

        foreach ($databases as $database) {
            $items[] = [
                'name' => (string) $database['db_name'],
                'detail' => (string) ($database['primary_domain'] ?? 'ไม่ผูกกับเว็บไซต์'),
                'url' => '/databases',
            ];
        }

        return ['kind' => 'databases', 'label' => 'ฐานข้อมูลที่ใช้งาน', 'items' => $items, 'total' => count($items)];
    }

    /** @param list<array<string,mixed>> $crons */
    private function crons(array $crons): array
    {
        $items = [];

        foreach ($crons as $cron) {
            if ((int) $cron['enabled'] !== 1) {
                continue;
            }

            $items[] = [
                'name' => (string) $cron['name'],
                'detail' => (string) $cron['schedule'],
                'url' => '/cron',
            ];
        }

        return ['kind' => 'crons', 'label' => 'งานอัตโนมัติที่เปิดใช้งาน', 'items' => $items, 'total' => count($items)];
    }

    /**
     * ข้อความเตือนผลกระทบก่อนหยุดบริการ — ใช้ในกล่องยืนยัน (SECURITY §4)
     * คำนวณจากข้อมูลจริงในระบบ ไม่ใช่ข้อความทั่วไป
     *
     * @param array{items:list<array{name:string,detail:string,url:string}>,total:int} $relation
     */
    public static function impactMessage(string $unit, array $relation, string $action): string
    {
        $label = ServiceCatalog::label($unit);
        $total = $relation['total'] ?? 0;

        if ($action === 'reload') {
            return "โหลดค่าตั้งใหม่ของ {$label} — บริการจะไม่หยุดทำงาน";
        }

        if ($total === 0) {
            return $action === 'stop'
                ? "ยืนยันหยุดบริการ {$label} หรือไม่"
                : "ยืนยันรีสตาร์ตบริการ {$label} หรือไม่";
        }

        $names = array_slice(array_column($relation['items'], 'name'), 0, 5);
        $list = implode(', ', $names);
        if ($total > 5) {
            $list .= ' และอีก ' . ($total - 5) . ' รายการ';
        }

        $kindWord = match ($relation['kind'] ?? '') {
            'databases' => 'ฐานข้อมูล',
            'crons' => 'งานอัตโนมัติ',
            default => 'เว็บไซต์',
        };

        return $action === 'stop'
            ? "การหยุดบริการนี้อาจทำให้{$kindWord}ที่เกี่ยวข้องไม่สามารถใช้งานได้\n\n{$kindWord} {$total} รายการที่ได้รับผลกระทบ: {$list}"
            : "การรีสตาร์ตจะทำให้{$kindWord}ที่เกี่ยวข้องหยุดให้บริการชั่วขณะ\n\n{$kindWord} {$total} รายการที่ได้รับผลกระทบ: {$list}";
    }
}
