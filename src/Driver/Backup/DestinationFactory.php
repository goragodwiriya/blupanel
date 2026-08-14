<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupDestinationRepository;

/**
 * ประกอบ driver ของปลายทางจากแถวในฐานข้อมูล — จุดเดียวที่ความลับถูกนำมาใช้
 *
 * แยกออกมาจาก repository เพราะ repository ไม่ควรรู้จัก driver และ driver ไม่ควรรู้จัก
 * ฐานข้อมูล · ตัวนี้เป็นที่เดียวที่ทั้งสองฝั่งมาเจอกัน ทำให้ตอบคำถาม "ความลับถูกใช้
 * ที่ไหนบ้าง" ได้ด้วยการอ่านไฟล์เดียว
 */
final class DestinationFactory
{
    public function __construct(
        private readonly BackupDestinationRepository $destinations,
        private readonly string $sourceDir = '',
    ) {
    }

    /**
     * @param array<string,mixed> $row แถวที่ผ่าน `present()` มาแล้ว (ไม่มีความลับ)
     */
    public function make(array $row): Destination
    {
        $id = (int) ($row['id'] ?? 0);
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        $driver = (string) ($row['driver'] ?? '');

        return match ($driver) {
            'local' => new LocalDestination((string) ($config['path'] ?? ''), $this->sourceDir),

            'sftp' => new SftpDestination(
                host: (string) ($config['host'] ?? ''),
                port: (int) ($config['port'] ?? 22),
                user: (string) ($config['user'] ?? ''),
                path: (string) ($config['path'] ?? ''),
                privateKey: $this->destinations->secretFor($id),
                knownHosts: (string) ($config['known_hosts'] ?? ''),
            ),

            'rsync' => new RsyncDestination(
                host: (string) ($config['host'] ?? ''),
                port: (int) ($config['port'] ?? 22),
                user: (string) ($config['user'] ?? ''),
                path: (string) ($config['path'] ?? ''),
                privateKey: $this->destinations->secretFor($id),
                knownHosts: (string) ($config['known_hosts'] ?? ''),
            ),

            's3' => new S3Destination(
                bucket: (string) ($config['bucket'] ?? ''),
                region: (string) ($config['region'] ?? ''),
                accessKey: (string) ($config['access_key'] ?? ''),
                secretKey: $this->destinations->secretFor($id),
                path: (string) ($config['path'] ?? ''),
                endpoint: (string) ($config['endpoint'] ?? ''),
                pathStyle: (bool) ($config['path_style'] ?? false),
            ),

            default => throw new ValidationError('ไม่รู้จักชนิดปลายทาง: ' . $driver),
        };
    }

    /**
     * ฟิลด์ที่แต่ละ driver ต้องการ — หน้าจอกับตัวตรวจค่าใช้รายการเดียวกัน
     *
     * @return array<string,list<string>>
     */
    public static function requiredFields(): array
    {
        return [
            'local' => ['path'],
            'sftp' => ['host', 'user', 'path'],
            'rsync' => ['host', 'user', 'path'],
            's3' => ['bucket', 'region', 'access_key'],
        ];
    }

    /** driver ที่ต้องมีความลับ (กุญแจ ssh / secret key) จึงจะทำงานได้ */
    public static function needsSecret(string $driver): bool
    {
        return in_array($driver, ['sftp', 'rsync', 's3'], true);
    }
}
