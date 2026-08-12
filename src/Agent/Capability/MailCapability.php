<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;

/**
 * ฐานของทุก capability ที่แตะกล่องจดหมาย — PLAN-MAIL เฟส M1
 *
 * **สิ่งที่ทุกตัวต้องทำเหมือนกันหลังแก้ฐานข้อมูล:** เขียนตารางค้นหาใหม่ทั้งชุดจาก
 * ภาพรวมทั้งเครื่อง แล้วเขียน `main.cf`/`master.cf` ให้ตรงกับว่ามีโดเมนเปิดเมลอยู่ไหม
 *
 * ถ้าปล่อยให้แต่ละตัวทำเอง วันหนึ่งจะมีตัวที่ลืมเขียนไฟล์ใดไฟล์หนึ่ง แล้วได้เครื่องที่
 * ฐานข้อมูลบอกอย่างแต่ Postfix ทำอีกอย่าง — ซึ่งกับเมลแปลว่ากล่องที่ลบแล้วยังรับเมลได้
 * หรือกล่องที่เพิ่งสร้างยังไม่มีตัวตน
 */
abstract class MailCapability implements Capability
{
    public function isMutating(): bool
    {
        return true;
    }

    /** ทุกอย่างที่แตะกล่องจดหมายเป็นสิทธิ์เดียวกัน — เจ้าของเว็บจัดการเมลของโดเมนตัวเองได้ */
    public function permission(): string
    {
        return 'mail.manage';
    }

    protected function repository(Context $context): MailboxRepository
    {
        return new MailboxRepository($context->db);
    }

    protected function mailboxes(Context $context): MailboxManager
    {
        return new MailboxManager(new Template($context->config->paths->templates()));
    }

    /**
     * เขียนไฟล์ตั้งค่าทั้งหมดให้ตรงกับฐานข้อมูลตอนนี้
     *
     * เรียกหลังแก้ฐานข้อมูลเสร็จเสมอ · ลำดับสำคัญ: `main.cf` ต้องรู้ก่อนว่าเปิดรับ
     * เมลไหม (มันตัดสินว่าจะฟังพอร์ต 25 หรือไม่) แล้วค่อยเขียนตารางค้นหาที่อ้างถึงกัน
     *
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    protected function sync(Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domains = $repository->enabledDomains();

        $settings = new SettingsRepository($context->db);
        $hostname = $settings->get('mail.hostname');

        if ($hostname === '') {
            $from = $settings->get('mail.from');
            $hostname = $from !== '' && str_contains($from, '@')
                ? substr($from, strpos($from, '@') + 1)
                : (gethostname() ?: '');
        }

        /*
         * ชื่อโฮสต์ของเมลต้องเป็นชื่อเต็มที่มีจุด — เซิร์ฟเวอร์ปลายทางใช้ค่านี้ตอน
         * ทักทาย (EHLO) และหลายเจ้าปฏิเสธชื่อที่ไม่มีจุดทันที
         *
         * เครื่องจำนวนมากตั้ง hostname เป็นชื่อสั้น ๆ (คอนเทนเนอร์แทบทุกตัวเป็นแบบนั้น)
         * ข้อความตรงนี้จึงต้องบอกวิธีแก้ ไม่ใช่แค่บอกว่าค่าไม่ถูกต้อง
         */
        if (!str_contains($hostname, '.')) {
            throw new ValidationError(
                'ชื่อโฮสต์ของเมลต้องเป็นชื่อเต็มที่มีจุด (เช่น mail.example.com) — ตอนนี้ได้ "'
                . ($hostname !== '' ? $hostname : 'ค่าว่าง')
                . '" · ตั้งค่า mail.hostname ในหน้าตั้งค่า หรือแก้ hostname ของเครื่องให้เป็นชื่อเต็ม',
            );
        }

        $postfix = new MailManager(new Template($context->config->paths->templates()));

        $outbound = $postfix->apply($executor, [
            'mode' => $settings->get('mail.mode') ?: 'local',
            'hostname' => $hostname,
            'from' => $settings->get('mail.from'),
            'relay_host' => $settings->get('mail.relay_host'),
            'relay_port' => $settings->int('mail.relay_port'),
            'relay_user' => $settings->get('mail.relay_user'),
            'relay_password' => $settings->get('mail.relay_password'),
            'relay_tls' => $settings->bool('mail.relay_tls'),
            'hosting' => $domains !== [],
            'tls_cert' => $settings->get('mail.tls_cert'),
            'tls_key' => $settings->get('mail.tls_key'),
        ], reload: false);

        $result = $this->mailboxes($context)->apply(
            $executor,
            $domains,
            $repository->activeMailboxes(),
            $repository->activeAliases(),
        );

        // เปิดหรือปิดเมลครั้งแรกเปลี่ยนพอร์ตที่ Postfix ฟัง ซึ่ง reload ทำให้ไม่ได้
        if ($outbound['restart_required'] ?? false) {
            $postfix->restart($executor);
        }

        return $result;
    }

    /**
     * หาโดเมนที่ผู้เรียกระบุ พร้อมตรวจว่ามีอยู่จริง
     *
     * @return array<string,mixed>
     */
    protected function domainOrFail(Context $context, string $domain): array
    {
        $row = $this->repository($context)->findDomain($domain);

        if ($row === null) {
            throw new ValidationError('ไม่พบโดเมน ' . $domain . ' ในระบบ');
        }

        return $row;
    }
}
