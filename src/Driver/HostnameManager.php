<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\BinaryPath;

/**
 * ชื่อโฮสต์ของเครื่อง — อ่านและเปลี่ยนจากหน้าเว็บได้
 *
 * ## ทำไมต้องมี
 *
 * ชื่อโฮสต์ไม่ใช่ของประดับ · Postfix ใช้เป็น `myhostname` ตอนแนะนำตัวกับเซิร์ฟเวอร์เมล
 * ปลายทาง (ปลายทางที่ตรวจเข้มปฏิเสธเมลจากเครื่องที่ชื่อไม่ตรงกับ rDNS) และเป็นชื่อที่
 * ใช้ขอใบรับรองให้ตัวหน้าจัดการเอง · เดิมเปลี่ยนได้จาก `hostnamectl` ที่คอนโซลเท่านั้น
 * ซึ่งขัดกับหลักของ panel ที่ว่าสิ่งที่ต้องตั้งตอนติดตั้งต้องแก้จากหน้าเว็บได้ภายหลัง
 *
 * ## `/etc/hosts` ต้องแก้ตามเสมอ
 *
 * `hostnamectl set-hostname` เปลี่ยนแค่ชื่อใน kernel — **ไม่แตะ `/etc/hosts`**
 * ผลคือ `hostname -f` ต้องพึ่ง DNS ล้วน ๆ ถ้า DNS ยังไม่กระจายหรือล่ม คำสั่งนั้นจะ
 * ค้างจนหมดเวลาแล้วคืนชื่อสั้น ซึ่งลามไปทำให้ Postfix แนะนำตัวด้วยชื่อผิดและ
 * `MailReadiness` รายงานผิด · เครื่องนี้เป็นตัวอย่าง: `/etc/hosts` มีแต่ `localhost`
 * บรรทัดเดียว FQDN จึงมาจาก DNS ทั้งหมด
 *
 * บรรทัด `127.0.1.1` เป็นธรรมเนียมของ Debian/Ubuntu สำหรับชื่อเครื่องที่ไม่ผูกกับ
 * การ์ดเน็ตใบใดใบหนึ่ง — ตรงข้ามกับการชี้ `127.0.0.1` ซึ่งจะทับรายการของ `localhost`
 *
 * ## สิ่งที่ **ไม่** ทำให้
 *
 * ไม่แก้ rDNS (ต้องขอกับผู้ให้บริการคลาวด์) ไม่ขอใบรับรองใหม่ และไม่รีสตาร์ต Postfix
 * — ทั้งสามอย่างเป็นการตัดสินใจแยกที่ผู้ดูแลควรสั่งเอง ผู้เรียกได้รายการนั้นกลับไป
 * เพื่อบอกต่อ ไม่ใช่ให้ระบบทำเงียบ ๆ แล้วเมลล่มโดยไม่มีใครรู้ว่าเพราะอะไร
 */
final class HostnameManager
{
    public const HOSTS_FILE = '/etc/hosts';

    /** @var list<string> hostnamectl อยู่ /usr/bin บน Debian/Ubuntu · /bin บางระบบ */
    private const HOSTNAMECTL_PATHS = ['/usr/bin/hostnamectl', '/bin/hostnamectl'];

    /**
     * ชื่อที่ตั้งอยู่ตอนนี้
     *
     * @return array{hostname:string,short:string,fqdn_resolves:bool}
     */
    public function read(Executor $executor): array
    {
        $static = trim($executor->exec(
            [BinaryPath::resolve($executor, self::HOSTNAMECTL_PATHS, 'systemd'), '--static'],
            timeout: 10,
        )->output());

        $short = strstr($static, '.', true) ?: $static;

        return [
            'hostname' => $static,
            'short' => $short,
            // มีบรรทัดใน /etc/hosts ไหม — ตัวชี้ว่าชื่อเต็มใช้ได้โดยไม่ต้องพึ่ง DNS
            'fqdn_resolves' => $static !== '' && $this->hostsHas($executor, $static),
        ];
    }

    /**
     * ตั้งชื่อใหม่ พร้อมดูแล `/etc/hosts` ให้สอดคล้อง
     *
     * @return array{hostname:string,previous:string,hosts_updated:bool,follow_up:list<string>}
     */
    public function apply(Executor $executor, string $hostname): array
    {
        $hostname = self::assertHostname($hostname);
        $previous = $this->read($executor)['hostname'];

        if ($previous === $hostname) {
            return [
                'hostname' => $hostname,
                'previous' => $previous,
                'hosts_updated' => $this->syncHostsFile($executor, $hostname),
                'follow_up' => [],
            ];
        }

        $result = $executor->exec(
            [BinaryPath::resolve($executor, self::HOSTNAMECTL_PATHS, 'systemd'), 'set-hostname', $hostname],
            timeout: 20,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'ตั้งชื่อโฮสต์ไม่สำเร็จ: ' . (trim($result->stderr) ?: trim($result->output())),
            );
        }

        return [
            'hostname' => $hostname,
            'previous' => $previous,
            'hosts_updated' => $this->syncHostsFile($executor, $hostname),
            // สิ่งที่ผู้ดูแลต้องตามไปทำเอง — ระบบทำให้ไม่ได้หรือไม่ควรทำเงียบ ๆ
            'follow_up' => [
                sprintf('ตั้ง rDNS ของไอพีให้ชี้กลับมาที่ %s ที่ผู้ให้บริการคลาวด์ — เมลปลายทางที่ตรวจเข้มจะปฏิเสธถ้าไม่ตรง', $hostname),
                'ขอใบรับรองใหม่ให้ชื่อนี้ถ้าจะใช้กับหน้าจัดการหรือเมล',
                'รีสตาร์ต Postfix เพื่อให้แนะนำตัวด้วยชื่อใหม่ (ถ้าเปิดเมลไว้)',
            ],
        ];
    }

    /**
     * ให้ `/etc/hosts` มีบรรทัดของชื่อเต็มเสมอ — เขียนทับบรรทัด `127.0.1.1` เดิมถ้ามี
     *
     * แก้เฉพาะบรรทัดนั้นบรรทัดเดียว ไม่แตะที่เหลือ — ไฟล์นี้มักมีรายการที่ผู้ดูแล
     * เพิ่มเอง (เครื่องในวงแลน, การชี้โดเมนทดสอบ) การเขียนทั้งไฟล์คือการลบของคนอื่น
     *
     * @return bool มีการเปลี่ยนแปลงจริงหรือไม่
     */
    private function syncHostsFile(Executor $executor, string $hostname): bool
    {
        $path = $executor->path(self::HOSTS_FILE);

        if (!$executor->exists($path)) {
            return false;
        }

        $short = strstr($hostname, '.', true) ?: $hostname;
        $wanted = $hostname === $short
            ? "127.0.1.1\t{$hostname}"
            : "127.0.1.1\t{$hostname} {$short}";

        $lines = preg_split('/\R/', $executor->readFile($path)) ?: [];
        $out = [];
        $replaced = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*127\.0\.1\.1\s/', $line) === 1) {
                // บรรทัดของเราเอง — แทนที่ ไม่ใช่เพิ่มซ้ำจนมีหลายบรรทัดขัดกัน
                if (!$replaced) {
                    $out[] = $wanted;
                    $replaced = true;
                }

                continue;
            }

            $out[] = $line;
        }

        if (!$replaced) {
            // วางไว้หลัง localhost ซึ่งเป็นบรรทัดแรกตามธรรมเนียม — ถ้าหาไม่เจอก็ต่อท้าย
            $at = null;
            foreach ($out as $i => $line) {
                if (preg_match('/^\s*127\.0\.0\.1\s/', $line) === 1) {
                    $at = $i + 1;

                    break;
                }
            }

            $at === null ? $out[] = $wanted : array_splice($out, $at, 0, [$wanted]);
        }

        $content = rtrim(implode("\n", $out), "\n") . "\n";

        if ($content === $executor->readFile($path)) {
            return false;
        }

        $executor->writeFile($path, $content, 0644);

        return true;
    }

    private function hostsHas(Executor $executor, string $hostname): bool
    {
        $path = $executor->path(self::HOSTS_FILE);

        if (!$executor->exists($path)) {
            return false;
        }

        foreach (preg_split('/\R/', $executor->readFile($path)) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];

            if (in_array($hostname, array_slice($parts, 1), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ชื่อโฮสต์ต้องสะอาดพอจะเขียนลง `/etc/hosts` และส่งให้ `hostnamectl` ได้
     *
     * เข้มกว่าที่ RFC อนุญาตโดยตั้งใจ: ห้ามขึ้นต้น/ลงท้ายด้วยขีดหรือจุด ห้ามจุดติดกัน
     * ห้าม label ยาวเกิน 63 ตัว และความยาวรวมไม่เกิน 253 · ค่านี้ถูกนำไปเขียนลงไฟล์
     * ของระบบและส่งเป็นอาร์กิวเมนต์ จึงต้องกันที่ต้นทาง ไม่ใช่หวังว่าปลายทางจะตรวจให้
     */
    public static function assertHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === '') {
            throw new ValidationError('ต้องระบุชื่อโฮสต์');
        }

        if (strlen($hostname) > 253) {
            throw new ValidationError('ชื่อโฮสต์ยาวเกิน 253 ตัวอักษร');
        }

        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $hostname) !== 1) {
            throw new ValidationError(
                "ชื่อโฮสต์ไม่ถูกต้อง: {$hostname}\n\n"
                . 'ใช้ได้เฉพาะ a-z 0-9 และขีด คั่นด้วยจุด · แต่ละส่วนยาวไม่เกิน 63 ตัว '
                . 'และห้ามขึ้นต้นหรือลงท้ายด้วยขีด',
            );
        }

        return $hostname;
    }
}
