<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * บันทึกเนื้อไฟล์ข้อความจากตัวแก้ไข
 *
 * เขียนผ่านไฟล์ชั่วคราวแล้ว rename ทับ (atomic) — ถ้าเครื่องดับกลางทาง
 * ไฟล์เดิมยังอยู่ครบ ไม่เหลือไฟล์ที่เขียนค้างครึ่งเดียวซึ่งจะทำให้เว็บล่มทันที
 */
final class FileWrite extends FileCapability
{
    public static function name(): string
    {
        return 'file.write';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'บันทึกเนื้อหาไฟล์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ที่จะบันทึก');
        }

        $content = $args['content'] ?? '';
        if (!is_string($content)) {
            throw new ValidationError('เนื้อหาไฟล์ต้องเป็นข้อความ');
        }
        if (strlen($content) > FileCatalog::MAX_EDIT_BYTES) {
            throw new ValidationError('เนื้อหายาวเกิน 5 MB');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new ValidationError('เนื้อหาต้องเป็นข้อความ UTF-8');
        }

        return $base + [
            'content' => $content,
            // สร้างไฟล์ใหม่ได้เมื่อผู้ใช้กด "ไฟล์ใหม่" — ค่าปริยายคือเขียนทับของเดิมเท่านั้น
            'create' => self::flag($args, 'create')
        ];
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
        $name = basename($relative);
        $content = $args['content'];
        $create = $args['create'];

        if (!FileCatalog::isEditable($name, strlen($content))) {
            throw new ValidationError('ไฟล์ชนิดนี้แก้ไขผ่านตัวจัดการไฟล์ไม่ได้');
        }

        $result = $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor, $content, $create): array {
                $info = $executor->stat($target);

                if ($info !== null) {
                    if ($create) {
                        throw new ValidationError('มีไฟล์ชื่อนี้อยู่แล้ว');
                    }
                    if ($info['type'] !== 'file') {
                        throw new ValidationError('เขียนทับได้เฉพาะไฟล์ธรรมดา');
                    }
                } elseif (!$create) {
                    throw new ValidationError('ไม่พบไฟล์ที่จะบันทึก');
                }

                // เขียนไฟล์ชั่วคราวข้าง ๆ ของเดิม (โฟลเดอร์เดียวกัน) เพื่อให้ rename
                // อยู่ใน filesystem เดียวกันและเป็น atomic จริง
                $mode = $info['mode'] ?? 0o640;
                $temporary = $target.'.phpcp-'.bin2hex(random_bytes(6));

                /*
                 * เจ้าของเดิมของไฟล์ (หรือของโฟลเดอร์แม่ ถ้าเป็นไฟล์ใหม่)
                 *
                 * **การแก้ไฟล์ต้องไม่เปลี่ยนเจ้าของ** — ผู้ดูแลระบบเปิดไฟล์ของลูกค้าผ่าน
                 * ขอบเขต "เว็บไซต์ทั้งหมด" ซึ่งทำงานด้วยสิทธิ์ของ agent (root) เพราะไฟล์
                 * ระดับเซิร์ฟเวอร์ไม่ได้เป็นของผู้ใช้คนใดคนหนึ่ง · ไฟล์ที่เขียนออกมาจึงเป็น
                 * ของ root แล้ว **FPM pool ของลูกค้าอ่านไม่ได้อีกเลย**
                 *
                 * เจอบนเซิร์ฟเวอร์จริง (2026-08-14): แก้ index.php จากตัวจัดการไฟล์แล้ว
                 * ทั้งเว็บตอบ 403 ทันที · Apache บอกว่า "Unable to open primary script
                 * (Permission denied)" ซึ่งไม่มีอะไรโยงกลับมาที่การกดบันทึกเมื่อครู่เลย
                 */
                $owner = $info ?? $executor->stat(dirname($target));

                $executor->writeFile($temporary, $content, $mode);

                try {
                    self::restoreOwner($executor, $temporary, $owner);
                    $executor->rename($temporary, $target);
                } catch (\Throwable $e) {
                    $executor->removePath($temporary);

                    throw $e;
                }

                return ['size' => strlen($content), 'mode' => sprintf('%04o', $mode)];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $name,
            'created' => $create,
            ...$result
        ];
    }

    /**
     * คืนเจ้าของไฟล์ให้เป็นคนเดิม — ทำก่อน rename เพื่อไม่ให้มีจังหวะที่ไฟล์จริงเป็นของ root
     *
     * ข้ามเมื่อไม่รู้เจ้าของ หรือเมื่อทำงานในสิทธิ์ที่ลดแล้วอยู่แล้ว (ขอบเขตของเว็บไซต์
     * ลดสิทธิ์เป็นเจ้าของก่อนแตะไฟล์ ไฟล์ที่เขียนออกมาจึงเป็นของเขาตั้งแต่แรก และ
     * `chown` จะล้มเพราะผู้ใช้ธรรมดาเปลี่ยนเจ้าของไฟล์ไม่ได้) · ล้มแล้วไม่โยนต่อ
     * ด้วยเหตุผลนั้น — แต่ยังต้องพยายาม เพราะกรณีที่สำคัญคือตอนทำงานเป็น root
     *
     * @param array<string,mixed>|null $owner ผลจาก stat() ของไฟล์เดิมหรือโฟลเดอร์แม่
     */
    private static function restoreOwner(Executor $executor, string $path, ?array $owner): void
    {
        $uid = (int) ($owner['uid'] ?? -1);
        $gid = (int) ($owner['gid'] ?? -1);

        if ($uid < 0 || $gid < 0) {
            return;
        }

        $executor->exec(
            [$executor->path('/usr/bin/chown'), sprintf('%d:%d', $uid, $gid), $path],
            timeout: 10,
        );
    }
}
