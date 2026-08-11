<?php

declare(strict_types=1);

namespace Phpcp\Support;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * หาไฟล์โปรแกรมตัวแรกที่มีอยู่จริงจากรายการเส้นทางที่ยอมรับได้
 *
 * **ทำไมต้องมี:** เครื่องมือเดียวกันอยู่คนละที่ตาม distro — `named-checkzone` อยู่
 * `/usr/bin` บน Debian/Ubuntu แต่ `/usr/sbin` บน RHEL · การฮาร์ดโค้ดที่เดียวทำให้
 * ทุกการเรียกล้มทั้งหมดบน distro อีกฝั่ง (เจอจริงในเฟส E3 — ดู PLAN-V2 §6)
 *
 * **ห้ามเรียกโปรแกรมด้วยชื่อล้วนแล้วให้ระบบหาใน `PATH` เอง** — agent รันเป็น root
 * การพึ่ง `PATH` เปิดช่องให้ยัดโปรแกรมปลอมไว้ในไดเรกทอรีที่มาก่อนแล้วได้สิทธิ์ root ทันที
 * คลาสนี้จึงรับเฉพาะเส้นทางเต็มที่ระบุไว้ล่วงหน้า ไม่มีการค้นหาแบบเปิด
 *
 * `tests/security/BinaryPathTest.php` ตรึงไว้ว่าทุกค่าคงที่ที่ driver ใช้ต้องชี้ไปยังไฟล์
 * ที่มีอยู่จริงบนเครื่องที่รันเทสต์ (เมื่อโปรแกรมนั้นติดตั้งอยู่)
 */
final class BinaryPath
{
    /**
     * @param list<string> $candidates เส้นทางเต็มที่ยอมรับได้ เรียงตามลำดับที่ควรลอง
     * @param string $package ชื่อแพ็กเกจที่ต้องติดตั้งถ้าไม่เจอ — ใส่ในข้อความให้ผู้ดูแลทำตามได้
     *
     * @throws ExecutionFailed เมื่อไม่พบสักตัว
     */
    public static function resolve(Executor $executor, array $candidates, string $package): string
    {
        foreach ($candidates as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                return $candidate;
            }
        }

        throw new ExecutionFailed(sprintf(
            "ไม่พบโปรแกรมที่ต้องใช้ (หาที่ %s)\n\nติดตั้งด้วย `apt install %s` แล้วลองใหม่",
            implode(' และ ', $candidates),
            $package,
        ));
    }
}
