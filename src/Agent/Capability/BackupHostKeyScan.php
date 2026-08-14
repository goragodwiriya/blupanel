<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * อ่าน host key ของเครื่องปลายทางมาให้ — `ssh-keyscan` ที่กดจากหน้าเว็บ
 *
 * ## ทำไมถึงคุ้มที่จะมี
 *
 * `StrictHostKeyChecking=yes` เปิดไว้เสมอ การตั้งปลายทาง sftp/rsync จึงล้มครั้งแรก
 * ทุกครั้งจนกว่าจะมี host key · ทางเดิมคือให้ผู้ดูแลไปหาเครื่องที่มี `ssh-keyscan`
 * รันเอง แล้ว copy ผลกลับมาวาง ซึ่งพลาดได้หลายจุด: พิมพ์พอร์ตผิด · copy ไม่ครบบรรทัด
 * · เครื่องที่รันมองเห็นคนละ IP กับที่ panel มองเห็น (NAT/split-horizon DNS) แล้วได้
 * กุญแจของคนละเครื่องมาโดยไม่รู้ตัว
 *
 * ปุ่มนี้รันจาก **เครื่องเดียวกับที่จะส่งไฟล์สำรองจริง** ผลที่ได้จึงเป็นกุญแจของเครื่อง
 * ที่มันจะคุยด้วยจริง ๆ ไม่ใช่ของเครื่องที่ผู้ดูแลบังเอิญนั่งอยู่
 *
 * ## สิ่งที่ปุ่มนี้**ไม่ได้**ทำ
 *
 * **ไม่ได้ยืนยันว่ากุญแจนั้นเป็นของจริง** — `ssh-keyscan` เชื่อสิ่งที่ปลายสายตอบมา
 * เหมือนกับการต่อครั้งแรกทุกประการ (trust on first use) · ถ้ามีคนดักอยู่กลางทาง
 * ตั้งแต่ก่อนกดปุ่ม กุญแจที่ได้ก็เป็นของผู้ดักนั้น
 *
 * ที่มันแก้จริงคือ **การพิมพ์ผิดและการหยิบผิดเครื่อง** ไม่ใช่การดักกลางทาง · ผู้ดูแล
 * ที่ต้องการความมั่นใจเต็มที่ยังต้องเทียบลายนิ้วมือกับที่อ่านจากคอนโซลของเครื่องปลายทาง
 * — คำตอบจึงแนบ fingerprint มาให้เทียบ แทนที่จะให้ไปหาเอง
 */
final class BackupHostKeyScan implements Capability
{
    private const KEYSCAN = '/usr/bin/ssh-keyscan';
    private const KEYGEN = '/usr/bin/ssh-keygen';

    public static function name(): string
    {
        return 'backup.host_key_scan';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    /**
     * อ่านอย่างเดียว — ไม่เปลี่ยนอะไรบนเครื่องนี้หรือเครื่องปลายทาง
     *
     * ผลพลอยได้คือได้ `Executor` จริงในโหมด dryrun จึงยังอ่านกุญแจได้ตอนที่ผู้ดูแล
     * กำลังลองตั้งค่าอยู่ ซึ่งเป็นตอนที่ต้องใช้พอดี
     */
    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่าน host key ของเครื่องปลายทาง';
    }

    public function validate(array $args): array
    {
        $host = trim(Validator::requireString($args, 'host', 255));

        /*
         * ค่านี้กลายเป็นอาร์กิวเมนต์ของคำสั่ง — ต้องเป็นชื่อโฮสต์หรือ IP เท่านั้น
         *
         * `Executor::exec()` รับ argv เป็น array อยู่แล้วจึงไม่มีเชลล์มาตีความ แต่
         * `ssh-keyscan` เองรับตัวเลือกที่ขึ้นต้นด้วย `-` · ค่าที่ขึ้นต้นแบบนั้นจะ
         * กลายเป็นตัวเลือกแทนที่จะเป็นชื่อเครื่อง
         */
        $hostname = '[A-Za-z0-9](?:[A-Za-z0-9.\-:]*[A-Za-z0-9])?';   // ชื่อโดเมน · IPv4 · IPv6 เปล่า
        $bracketed = '\[[0-9A-Fa-f:]+\]';                            // IPv6 ในวงเล็บเหลี่ยม

        if (preg_match('/^(?:' . $hostname . '|' . $bracketed . ')$/', $host) !== 1) {
            throw new ValidationError('ชื่อเครื่องปลายทางต้องเป็นชื่อโฮสต์หรือหมายเลข IP เท่านั้น');
        }

        $port = (int) ($args['port'] ?? 22);

        if ($port < 1 || $port > 65535) {
            throw new ValidationError('พอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
        }

        return ['host' => $host, 'port' => $port];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)
            && $context->actor->userId !== 0) {
            throw new PermissionDenied('การอ่าน host key ต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        $result = $executor->exec([
            self::KEYSCAN,
            '-T', '10',
            '-p', (string) $args['port'],
            $args['host'],
        ], timeout: 30);

        // ssh-keyscan เขียนบรรทัดสถานะลง stderr เป็นปกติ จึงตัดสินจาก stdout ที่ได้
        $keys = trim($result->stdout);

        if ($keys === '') {
            throw new ExecutionFailed(
                'อ่าน host key ของ ' . $args['host'] . ':' . $args['port'] . ' ไม่ได้'
                . ' — ตรวจว่าเครื่องปลายทางเปิดอยู่ พอร์ตถูกต้อง และไฟร์วอลล์ยอมให้เครื่องนี้ต่อเข้าไป'
                . ($result->stderr !== '' ? "\n\n" . trim($result->stderr) : ''),
            );
        }

        return [
            'host' => $args['host'],
            'port' => $args['port'],
            'known_hosts' => $keys,
            'lines' => count(array_filter(explode("\n", $keys), static fn (string $l): bool => trim($l) !== '')),
            // ให้เทียบกับที่อ่านจากคอนโซลของเครื่องปลายทางได้ — ปุ่มนี้ยืนยันตัวตนแทนไม่ได้
            'fingerprints' => $this->fingerprints($executor, $keys),
            'message' => sprintf('อ่าน host key ของ %s:%d มาแล้ว', $args['host'], $args['port']),
        ];
    }

    /**
     * ลายนิ้วมือของกุญแจที่อ่านมา — สิ่งเดียวที่เทียบกับเครื่องปลายทางด้วยตาได้
     *
     * ล้มแล้วไม่ถือว่าทั้งคำสั่งล้ม · กุญแจยังใช้ได้ แค่เทียบด้วยตาไม่ได้เท่านั้น
     *
     * @return list<string>
     */
    private function fingerprints(Executor $executor, string $keys): array
    {
        $file = sys_get_temp_dir() . '/phpcp-scan-' . bin2hex(random_bytes(6));

        try {
            $executor->writeFile($executor->path($file), $keys . "\n", 0600);

            $result = $executor->exec([self::KEYGEN, '-l', '-f', $executor->path($file)], timeout: 15);

            if (!$result->ok()) {
                return [];
            }

            return array_values(array_filter(
                array_map(trim(...), explode("\n", $result->stdout)),
                static fn (string $line): bool => $line !== '',
            ));
        } catch (\Throwable) {
            return [];
        } finally {
            if ($executor->exists($executor->path($file))) {
                $executor->removePath($executor->path($file));
            }
        }
    }
}
