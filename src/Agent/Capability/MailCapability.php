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

    /**
     * ชื่อโฮสต์ของเมลที่เครื่องนี้ใช้จริง
     *
     * **ต้องเป็นที่เดียวทั้งระบบ** — ค่านี้ถูกใช้สามที่ที่ต้องตอบตรงกันเสมอ: ตอนเขียน
     * `myhostname` ลง Postfix · ตอนหาว่าใบรับรองใบไหนครอบคลุมชื่อนี้ · ตอนรายงานความพร้อม
     *
     * ตอนที่แต่ละที่อ่านเอง `mail.cert` กับหน้าความพร้อมอ่านแค่ `mail.hostname` ตรง ๆ
     * ส่วน `sync()` อนุมานต่อจาก `mail.from` ได้ · เครื่องที่ไม่เคยกรอกช่องชื่อโฮสต์จึงมี
     * Postfix ที่ประกาศชื่อถูกต้องอยู่ แต่ปุ่มผูกใบรับรองตอบว่า "ยังไม่ได้ตั้งชื่อโฮสต์"
     * แล้วไม่ทำอะไรเลย — สองส่วนของระบบเดียวกันไม่เห็นตรงกันว่าเครื่องชื่ออะไร
     */
    protected static function mailHostname(SettingsRepository $settings): string
    {
        $hostname = trim($settings->get('mail.hostname'));

        if ($hostname !== '') {
            return $hostname;
        }

        // ส่วนโดเมนของที่อยู่ผู้ส่งเป็นการเดาที่ดีที่สุดที่มี — เจ้าของเครื่องกรอกไว้แล้ว
        $from = trim($settings->get('mail.from'));

        if ($from !== '' && str_contains($from, '@')) {
            return substr($from, strpos($from, '@') + 1);
        }

        return gethostname() ?: '';
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
     * **ทางนี้เป็นทางเดียวที่เขียน `main.cf` ได้** — รวมถึงการกดบันทึกค่าเมลขาออกจาก
     * หน้าตั้งค่า (`mail.apply`) · เขียนจากที่อื่นแปลว่าต้องจำเองว่ามีเมลโฮสติ้งเปิดอยู่
     * ไหม ซึ่งลืมเมื่อไหร่ก็ได้ `main.cf` ที่ไม่มีส่วนรับเมลเลย: กล่องทุกกล่องบนเครื่อง
     * หยุดรับเมลเงียบ ๆ ทั้งที่ผู้ใช้แค่กดบันทึกที่อยู่ผู้ส่ง
     *
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    protected function sync(Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domains = $repository->enabledDomains();

        $settings = new SettingsRepository($context->db);
        $hostname = self::mailHostname($settings);

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
            // ชื่อเครื่องเป็นโดเมนของกล่องจดหมายด้วยไหม — ถ้าใช่ ห้ามใส่ใน mydestination
            'virtual_hostname' => in_array($hostname, $domains, true),
            'tls_cert' => $settings->get('mail.tls_cert'),
            'tls_key' => $settings->get('mail.tls_key'),
        ], reload: false);

        $mailboxes = $this->mailboxes($context);

        /*
         * **เครื่องที่ส่งเมลอย่างเดียวไม่ต้องมี Dovecot** — และเป็นเครื่องส่วนใหญ่
         *
         * `mail.apply` (ปุ่มบันทึกค่าเมลขาออกในหน้าตั้งค่า) เดินผ่านทางนี้ด้วย ซึ่ง
         * เครื่องเหล่านั้นมีแค่ Postfix ไว้ส่งเมลแจ้งเตือน · การเขียนตารางกล่องจดหมาย
         * ที่นั่นจะล้มที่ `doveconf -n` ทั้งที่ไม่มีกล่องให้เขียนสักกล่อง
         */
        if ($domains === [] && !$mailboxes->isInstalled($executor)) {
            // reload เปลี่ยนพอร์ตที่ฟังไม่ได้ · เครื่องที่เคยฟังพอร์ต 25 อยู่ต้องหยุดฟังจริง
            ($outbound['restart_required'] ?? false)
                ? $postfix->restart($executor)
                : $postfix->reload($executor);

            return ['domains' => 0, 'mailboxes' => 0, 'aliases' => 0];
        }

        $result = $mailboxes->apply(
            $executor,
            $domains,
            $repository->activeMailboxes(),
            $repository->activeAliases(),
            // Dovecot ต้องได้ใบใบเดียวกับ Postfix — โปรแกรมเมลต่อ IMAP เข้า Dovecot ตรง ๆ
            ['cert' => $settings->get('mail.tls_cert'), 'key' => $settings->get('mail.tls_key')],
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
