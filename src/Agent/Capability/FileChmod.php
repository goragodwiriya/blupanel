<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * เปลี่ยนสิทธิ์ของไฟล์หรือโฟลเดอร์
 *
 * ยอมรับเฉพาะค่าที่อยู่ในรายการที่ปลอดภัย ไม่ใช่เลขฐานแปดอะไรก็ได้:
 * 0777 บนไฟล์ในเว็บทำให้ผู้ใช้อื่นบนเครื่องเดียวกันเขียนทับสคริปต์ของเว็บได้
 * และ setuid/setgid/sticky bit ไม่มีเหตุผลให้ตั้งจากแผงควบคุมเว็บเลย
 */
final class FileChmod extends FileCapability
{
    /** @var list<int> */
    private const ALLOWED = [0o600, 0o640, 0o644, 0o700, 0o750, 0o755];

    public static function name(): string
    {
        return 'file.chmod';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เปลี่ยนสิทธิ์ไฟล์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์หรือโฟลเดอร์');
        }

        $raw = Validator::requireString($args, 'mode', 4);
        if (preg_match('/^[0-7]{3,4}$/', $raw) !== 1) {
            throw new ValidationError('สิทธิ์ต้องเป็นเลขฐานแปด 3 หรือ 4 หลัก');
        }

        $mode = (int) octdec($raw);
        if (!in_array($mode, self::ALLOWED, true)) {
            throw new ValidationError('ระบบอนุญาตเฉพาะสิทธิ์ '.implode(', ', array_map(
                static fn(int $m): string => sprintf('%04o', $m),
                self::ALLOWED,
            )));
        }

        return $base + ['mode' => $mode, 'recursive' => self::flag($args, 'recursive')];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $relative = $args['path'];
        $mode = $args['mode'];
        $recursive = $args['recursive'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $mode, $recursive): array {
            $info = $executor->stat($target);
            if ($info === null) {
                throw new ValidationError('ไม่พบไฟล์ที่ระบุ');
            }
            if ($info['type'] === 'link') {
                throw new ValidationError('เปลี่ยนสิทธิ์ของ symlink ไม่ได้');
            }

            $changed = 1;
            $executor->changeMode($target, $mode);

            if ($recursive && $info['type'] === 'dir') {
                $changed += self::descend($executor, $target, $mode);
            }

            return ['changed' => $changed];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'mode' => sprintf('%04o', $mode),
            'changed' => $result['changed'],
            'message' => sprintf('เปลี่ยนสิทธิ์ %d รายการเป็น %04o แล้ว', $result['changed'], $mode)
        ];
    }

    /**
     * ไล่เปลี่ยนสิทธิ์ในโฟลเดอร์ย่อย
     *
     * ไฟล์กับโฟลเดอร์ต้องได้สิทธิ์คนละแบบ: โฟลเดอร์ที่ไม่มี execute bit จะเข้าไม่ได้เลย
     * การใส่ 0644 ทั้งต้นไม้จึงทำให้เว็บพังทั้งเว็บ — เติม execute ให้เฉพาะโฟลเดอร์
     */
    private static function descend(Executor $executor, string $directory, int $mode): int
    {
        // ใครอ่านได้ก็ต้องเข้าไปในโฟลเดอร์ได้ — เลื่อน read bit (4) ไปเป็น execute bit (1)
        // 0644 จึงกลายเป็น 0755 สำหรับโฟลเดอร์ และ 0640 กลายเป็น 0750
        $directoryMode = $mode | (($mode & 0o444) >> 2);
        $count = 0;

        foreach ($executor->listDirectory($directory) as $entry) {
            if ($entry['type'] === 'link') {
                continue; // ไม่เดินตามลิงก์ ปลายทางอาจอยู่นอกบ้านของเว็บไซต์
            }

            $child = $directory.'/'.$entry['name'];

            if ($entry['type'] === 'dir') {
                $executor->changeMode($child, $directoryMode);
                $count += 1 + self::descend($executor, $child, $mode);
                continue;
            }

            $executor->changeMode($child, $mode);
            $count++;
        }

        return $count;
    }
}
