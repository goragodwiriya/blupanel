<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * ตัวจำลองคำสั่งหนึ่งชนิดในโหมด sandbox
 *
 * เพิ่มคำสั่งใหม่ = เพิ่มคลาสที่ implement interface นี้แล้วลงทะเบียนใน SandboxExecutor
 * คำสั่งที่ไม่มีตัวจำลองจะถูกส่งไปรันจริง (โดย path ถูกแมปเข้า prefix แล้ว)
 * ซึ่งจงใจให้เป็นแบบนั้น เช่น apache2ctl -t ต้องรันจริงเพื่อตรวจ vhost ที่ generate ออกมา
 */
interface Simulator
{
    public function handles(string $binary): bool;

    /**
     * @param list<string> $argv
     * @param string|null  $stdin ข้อมูลที่ถูกป้อนเข้า stdin — จำเป็นสำหรับคำสั่ง
     *                            ที่รับคำสั่งทาง stdin เช่น MariaDB client
     */
    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult;
}
