<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Template;

/**
 * เวอร์ชัน PHP ที่ติดตั้งบนเครื่อง พร้อมสถานะ FPM และจำนวนเว็บไซต์ที่ใช้ — PROMPT.md
 *
 * อยู่ในหมวด Hosting เพราะตอบคำถามว่า "เว็บไซต์ใช้เวอร์ชันไหนได้บ้าง"
 * ส่วนการ start/stop โปรเซส PHP-FPM เป็นเรื่องของหน้า Services ตาม Important UX Rule
 */
final class PhpList implements \Phpcp\Agent\Capability
{
    public static function name(): string
    {
        return 'php.list';
    }

    public function permission(): string
    {
        return 'php.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านรายการเวอร์ชัน PHP ที่ติดตั้งไว้';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $fpm = new FpmManager(new Template($context->config->paths->templates()));
        $usage = (new SiteRepository($context->db))->countByPhpVersion();

        $versions = [];

        foreach ($fpm->installedVersions($executor) as $version) {
            $unit = ServiceCatalog::fpmUnit($version);
            $service = ServiceProbe::read($executor, $unit);
            $extensions = $fpm->extensions($executor, $version);

            $versions[$version] = [
                'version' => $version,
                'unit' => $unit,
                'fpm_status' => $service['status'],
                'fpm_running' => $service['running'],
                'fpm_memory' => $service['memory_bytes'],
                'sites' => $usage[$version] ?? 0,
                'extensions' => $extensions,
                'extension_count' => count($extensions),
                'supported' => self::isSupported($version),
                'cli_binary' => '/usr/bin/php' . $version,
            ];
        }

        return [
            'versions' => $versions,
            'installed_count' => count($versions),
            'default' => self::preferredVersion(array_keys($versions)),
        ];
    }

    /**
     * เวอร์ชันที่ยังได้รับการสนับสนุนด้านความปลอดภัยจากทีม PHP
     *
     * ใช้ตัดสินใจว่าจะขึ้นคำเตือนในหน้า PHP และหักคะแนนใน Security Center หรือไม่
     * รายการนี้ต้องอัปเดตตามรอบการสนับสนุนจริง — ตรวจได้ที่ php.net/supported-versions
     */
    private static function isSupported(string $version): bool
    {
        return in_array($version, ['8.5', '8.4', '8.3'], true);
    }

    /** @param list<string> $versions */
    private static function preferredVersion(array $versions): string
    {
        foreach (ServiceCatalog::PHP_VERSIONS as $candidate) {
            if (in_array($candidate, $versions, true) && self::isSupported($candidate)) {
                return $candidate;
            }
        }

        return $versions[0] ?? '';
    }
}
