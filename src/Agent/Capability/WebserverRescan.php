<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * ตรวจว่าไฟล์ vhost บนดิสก์ยังตรงกับความจริงของเว็บไซต์ไหม ถ้าไม่ตรงก็เขียนใหม่ให้
 *
 * **ทำไมต้องมีงานนี้:** ในโหมด nginx-proxy การตัดสินว่า nginx ตอบไฟล์ static เองได้ไหม
 * ขึ้นกับเนื้อหาของ `.htaccess` ที่รากเว็บ ซึ่ง**ลูกค้าแก้เองได้ตลอดเวลาผ่าน SFTP
 * หรือตัวจัดการไฟล์** โดยที่ panel ไม่มีทางรู้ · ถ้าไม่มีอะไรตรวจซ้ำ ลูกค้าที่เพิ่งเพิ่ม
 * กฎป้องกันลงไปจะเข้าใจว่าป้องกันแล้วทั้งที่ยังเปิดอยู่ จนกว่าจะมีใครไปกดแก้เว็บนั้น
 *
 * โฟลเดอร์ย่อยมีตาข่ายอีกชั้นอยู่แล้ว (nginx ตรวจ `.htaccess` ตอนรับคำขอทุกครั้ง)
 * งานนี้จึงมีไว้ปิดช่องของ **ไฟล์ที่รากเว็บ** เป็นหลัก
 *
 * เขียนใหม่เฉพาะเมื่อเนื้อหาต่างจริง — ไม่ใช่ reload ทุกชั่วโมงโดยไม่มีเหตุ
 */
final class WebserverRescan extends SiteCapability
{
    public static function name(): string
    {
        return 'webserver.rescan';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ตรวจว่าไฟล์ตั้งค่าของเว็บไซต์ยังตรงกับความจริงไหม';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $webserver = $this->provisioner($context)->webserver();
        $changed = [];

        foreach ($repository->listWithCounts() as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            foreach ($webserver->vhostFiles($site, $executor) as $path => $expected) {
                $resolved = $executor->path($path);

                if (!$executor->exists($resolved) || $executor->readFile($resolved) !== $expected) {
                    $changed[] = $site->domain;
                    break;
                }
            }
        }

        if ($changed === []) {
            return [
                'changed' => [],
                'rebuilt' => false,
                'message' => 'ไฟล์ตั้งค่าของทุกเว็บไซต์ยังตรงกับความจริง',
            ];
        }

        // ต่างแม้เว็บเดียวก็เขียนใหม่ทั้งชุดในทรานแซกชันเดียว — ถูกกว่าการไล่เขียนทีละเว็บ
        // แล้ว reload หลายรอบ และได้ configtest ที่ครอบคลุมทั้งเครื่องไปในตัว
        $result = (new SiteRebuild())->run([], $executor, $context);

        return [
            'changed' => array_values(array_unique($changed)),
            'rebuilt' => true,
            'message' => sprintf(
                'ไฟล์ตั้งค่าของ %d เว็บไซต์ไม่ตรงกับความจริงแล้ว จึงเขียนใหม่ทั้งหมด (%s)',
                count(array_unique($changed)),
                implode(', ', array_slice(array_unique($changed), 0, 5)),
            ),
        ] + ['detail' => $result['message'] ?? ''];
    }
}
