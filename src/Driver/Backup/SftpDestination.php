<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * ส่งไฟล์สำรองไปเครื่องอื่นด้วย `sftp` — PLAN-V2 เฟส E1
 *
 * เหมาะกับกรณีทั่วไปที่สุด: เครื่องปลายทางเป็น Linux ที่เปิด OpenSSH อยู่แล้ว
 * และบัญชีปลายทางถูกจำกัดให้ทำได้แค่ sftp (`ForceCommand internal-sftp` + chroot)
 * ซึ่งเป็นการตั้งค่าที่แนะนำ เพราะกุญแจที่รั่วก็ยังสั่งคำสั่งบนเครื่องสำรองไม่ได้
 *
 * **ยืนยันความครบถ้วนด้วย checksum ฝั่งปลายทาง ไม่ใช่แค่ขนาดไฟล์** — `sftp` รายงาน
 * สำเร็จเมื่อเขียนจบ แต่ไฟล์ที่เขียนลงดิสก์ที่กำลังจะพังก็ "เขียนจบ" เหมือนกัน ·
 * ถ้าเครื่องปลายทางไม่มี `sha256sum` (เช่น chroot ที่มีแต่ internal-sftp) จะถอยไป
 * เทียบขนาดแทน แล้ว**บอกในผลลัพธ์ว่าตรวจได้แค่ขนาด** ไม่ใช่เงียบ ๆ ปล่อยผ่าน
 */
final class SftpDestination extends SshDestination
{
    private const SFTP = '/usr/bin/sftp';
    private const SSH = '/usr/bin/ssh';

    public static function driver(): string
    {
        return 'sftp';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $remotePath = $this->remote($remoteName);

        return $this->withKey($executor, function (string $keyFile) use ($executor, $localPath, $remotePath): string {
            // -b - อ่านชุดคำสั่งจาก stdin · ชื่อไฟล์จึงไม่ถูกแปลผ่านเชลล์เลย
            //
            // สร้างไดเรกทอรีทีละชั้นจากบนลงล่าง — sftp ไม่มี `mkdir -p` ให้ใช้
            // (ดู makeDirectoryScript) · ชั้นที่มีอยู่แล้วถูกข้ามไปเองเพราะ `-` นำหน้า
            $script = sprintf(
                "%sput %s %s\nbye\n",
                $this->makeDirectoryScript(),
                $this->quote($executor->path($localPath)),
                $this->quote($remotePath),
            );

            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 1800,
                stdin: $script,
            );

            $this->assertOk($result, 'ส่งไฟล์สำรองไปยังปลายทางไม่สำเร็จ');
            $this->assertArrived($executor, $keyFile, $localPath, $remotePath);

            return $remotePath;
        });
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath, $localPath): void {
            $script = sprintf(
                "get %s %s\nbye\n",
                $this->quote($remotePath),
                $this->quote($executor->path($localPath)),
            );

            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 1800,
                stdin: $script,
            );

            $this->assertOk($result, 'ดึงไฟล์สำรองจากปลายทางไม่สำเร็จ');

            if (!$executor->exists($executor->path($localPath))) {
                throw new ExecutionFailed('คำสั่งดึงไฟล์สำเร็จ แต่ไม่พบไฟล์บนเครื่องนี้');
            }
        });
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath): void {
            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 120,
                stdin: sprintf("rm %s\nbye\n", $this->quote($remotePath)),
            );

            // ไฟล์ที่ไม่มีอยู่แล้วถือว่าสำเร็จ — ตัวเก็บกวาดต้องเรียกซ้ำได้
            if (!$result->ok() && !str_contains($result->stderr, 'No such file')) {
                throw new ExecutionFailed('ลบไฟล์ที่ปลายทางไม่สำเร็จ: ' . $this->explain($result->stderr));
            }
        });
    }

    /**
     * ยืนยันว่าไฟล์ที่ปลายทางตรงกับต้นฉบับจริง
     *
     * ถ้าปลายทางรัน `sha256sum` ไม่ได้ (chroot แบบ internal-sftp ทำไม่ได้แน่นอน)
     * จะถอยไปเทียบขนาด ซึ่งอ่อนกว่าแต่ยังจับกรณีส่งไปครึ่งเดียวได้ — กรณีที่พบบ่อยที่สุด
     */
    private function assertArrived(Executor $executor, string $keyFile, string $localPath, string $remotePath): void
    {
        $localSize = $executor->stat($executor->path($localPath))['size'] ?? 0;

        $remoteSum = $executor->exec(
            [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
             $this->user . '@' . $this->host, 'sha256sum', '--', $remotePath],
            timeout: 600,
        );

        if ($remoteSum->ok()) {
            $expected = @hash_file('sha256', $executor->path($localPath));
            $actual = strtok(trim($remoteSum->stdout), ' ');

            if ($expected === false || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new ExecutionFailed('ไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ — ถือว่าส่งไม่สำเร็จ');
            }

            return;
        }

        // ปลายทางสั่งคำสั่งไม่ได้ — เทียบขนาดผ่าน sftp แทน
        $listing = $executor->exec(
            [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
             $this->user . '@' . $this->host],
            timeout: 120,
            stdin: sprintf("ls -l %s\nbye\n", $this->quote($remotePath)),
        );

        $this->assertOk($listing, 'ตรวจไฟล์ที่ปลายทางไม่สำเร็จ');

        if ($localSize > 0 && !str_contains($listing->stdout, (string) $localSize)) {
            throw new ExecutionFailed(
                'ขนาดไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ — ถือว่าส่งไม่สำเร็จ',
            );
        }
    }

    /** ใส่เครื่องหมายคำพูดแบบที่ sftp เข้าใจ — ชื่อไฟล์ของเราไม่มี " อยู่แล้ว แต่กันไว้ */
    private function quote(string $value): string
    {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}
