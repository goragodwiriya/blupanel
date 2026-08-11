<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * ส่งไฟล์สำรองด้วย `rsync` over ssh — PLAN-V2 เฟส E1
 *
 * **ต่างจาก sftp ตรงไหน:** rsync ส่งเฉพาะส่วนที่ต่างจากไฟล์ที่ปลายทางมีอยู่แล้ว และ
 * ส่งต่อจากจุดที่ค้างได้เมื่อเน็ตหลุด (`--partial`) · สำคัญมากกับไฟล์สำรองขนาดหลาย
 * กิกะไบต์ที่ส่งข้ามอินเทอร์เน็ตทุกคืน ซึ่งเป็นกรณีที่ sftp ต้องเริ่มใหม่ทั้งไฟล์ทุกครั้ง
 *
 * **`--checksum` ไม่ได้เปิดตอนส่ง** เพราะมันบังคับให้อ่านไฟล์ทั้งก้อนทั้งสองฝั่งก่อน
 * ตัดสินใจ ซึ่งช้ากว่าการส่งไฟล์ใหม่ทั้งไฟล์ในหลายกรณี · ความครบถ้วนถูกยืนยันหลังส่ง
 * ด้วยการเทียบ checksum ครั้งเดียว ซึ่งได้ผลเท่ากันแต่จ่ายค่าอ่านแค่รอบเดียว
 *
 * ต้องมี `rsync` ทั้งสองฝั่ง — ถ้าปลายทางไม่มี ให้ใช้ปลายทางแบบ sftp แทน
 */
final class RsyncDestination extends SshDestination
{
    private const RSYNC = '/usr/bin/rsync';
    private const SSH = '/usr/bin/ssh';

    public static function driver(): string
    {
        return 'rsync';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $remotePath = $this->remote($remoteName);

        return $this->withKey($executor, function (string $keyFile) use ($executor, $localPath, $remotePath): string {
            // สร้างไดเรกทอรีปลายทางก่อน — rsync ไม่สร้างชั้นกลางให้เองถ้าไม่ใช้ --relative
            $mkdir = $executor->exec(
                [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
                 $this->user . '@' . $this->host, 'mkdir', '-p', '--', dirname($remotePath)],
                timeout: 120,
            );

            $this->assertOk($mkdir, 'สร้างไดเรกทอรีที่ปลายทางไม่สำเร็จ');

            $result = $executor->exec([
                self::RSYNC,
                '--archive',
                '--partial',          // เน็ตหลุดแล้วส่งต่อจากจุดเดิมได้ ไม่ต้องเริ่มใหม่
                '--compress',
                '--times',
                '--chmod=F600',       // ไฟล์สำรองที่ปลายทางต้องไม่ให้คนอื่นบนเครื่องนั้นอ่าน
                '--rsh', $this->rshCommand($keyFile),
                $executor->path($localPath),
                sprintf('%s@%s:%s', $this->user, $this->host, $remotePath),
            ], timeout: 3600);

            $this->assertOk($result, 'ส่งไฟล์สำรองไปยังปลายทางไม่สำเร็จ');
            $this->assertArrived($executor, $keyFile, $localPath, $remotePath);

            return $remotePath;
        });
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath, $localPath): void {
            $result = $executor->exec([
                self::RSYNC,
                '--archive',
                '--partial',
                '--compress',
                '--rsh', $this->rshCommand($keyFile),
                sprintf('%s@%s:%s', $this->user, $this->host, $remotePath),
                $executor->path($localPath),
            ], timeout: 3600);

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
            // `rm -f` ไม่ล้มเมื่อไฟล์หายไปแล้ว ซึ่งเป็นสิ่งที่ตัวเก็บกวาดต้องการพอดี
            $result = $executor->exec(
                [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
                 $this->user . '@' . $this->host, 'rm', '-f', '--', $remotePath],
                timeout: 120,
            );

            $this->assertOk($result, 'ลบไฟล์ที่ปลายทางไม่สำเร็จ');
        });
    }

    /** ยืนยันด้วย sha256 ที่ปลายทาง — rsync มี rsync เสมอ จึงคาดหวังเชลล์ได้ */
    private function assertArrived(Executor $executor, string $keyFile, string $localPath, string $remotePath): void
    {
        $remoteSum = $executor->exec(
            [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
             $this->user . '@' . $this->host, 'sha256sum', '--', $remotePath],
            timeout: 600,
        );

        $this->assertOk($remoteSum, 'ตรวจไฟล์ที่ปลายทางไม่สำเร็จ');

        $expected = @hash_file('sha256', $executor->path($localPath));
        $actual = strtok(trim($remoteSum->stdout), ' ');

        if ($expected === false || !is_string($actual) || !hash_equals($expected, $actual)) {
            throw new ExecutionFailed('ไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ — ถือว่าส่งไม่สำเร็จ');
        }
    }

    /**
     * คำสั่ง ssh ที่ rsync จะใช้
     *
     * `--rsh` รับเป็นสตริงเดียวที่ rsync แยกคำเอง · ค่าทุกตัวในนี้มาจากฟิลด์ที่ถูก
     * ตรวจรูปแบบแล้วตอนสร้างอ็อบเจกต์ (host/port/path) หรือเป็นเส้นทางไฟล์ที่เราสร้างเอง
     * จึงไม่มีค่าที่ผู้ใช้พิมพ์อิสระหลุดเข้ามาในสตริงนี้
     */
    private function rshCommand(string $keyFile): string
    {
        return implode(' ', [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port]);
    }
}
