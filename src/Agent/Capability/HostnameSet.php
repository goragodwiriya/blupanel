<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\HostnameManager;

/**
 * ตั้งชื่อโฮสต์ของเครื่อง — เดิมทำได้จากคอนโซลเท่านั้น
 *
 * ชื่อนี้ไปโผล่ที่ Postfix (`myhostname` ตอนแนะนำตัวกับเซิร์ฟเวอร์เมลปลายทาง) และเป็น
 * ชื่อที่ใช้ขอใบรับรองให้หน้าจัดการ · การที่มันแก้จากหน้าเว็บไม่ได้แปลว่าเครื่องที่
 * ตั้งชื่อผิดตอนติดตั้งต้องมีคนเข้าคอนโซลไปแก้ ซึ่งเป็นสิ่งที่ panel มีไว้เพื่อไม่ต้องทำ
 *
 * ใช้สิทธิ์ `settings.manage` เดียวกับค่าตั้งระดับเครื่องอื่น ๆ — ไม่ใช่ `service.control`
 * เพราะไม่ได้สั่งบริการ และไม่ใช่สิทธิ์ของหมวด hosting เพราะกระทบทั้งเครื่อง
 */
final class HostnameSet implements Capability
{
    public static function name(): string
    {
        return 'system.hostname_set';
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
        return 'ตั้งชื่อโฮสต์ของเครื่อง';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        return ['hostname' => HostnameManager::assertHostname((string) ($args['hostname'] ?? ''))];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $result = (new HostnameManager())->apply($executor, $args['hostname']);

        $message = $result['previous'] === $result['hostname']
            ? sprintf('ชื่อโฮสต์เป็น %s อยู่แล้ว', $result['hostname'])
            : sprintf('เปลี่ยนชื่อโฮสต์จาก %s เป็น %s แล้ว', $result['previous'], $result['hostname']);

        if ($result['hosts_updated']) {
            // บอกด้วยว่าแตะ /etc/hosts เพราะเป็นไฟล์ที่ผู้ดูแลหลายคนแก้เองไว้
            $message .= ' · ปรับบรรทัดใน /etc/hosts ให้ตรงกันแล้ว';
        }

        return $result + ['message' => $message];
    }
}
