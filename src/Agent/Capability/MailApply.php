<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * เขียนค่าตั้งเมลขาออกลง Postfix
 *
 * แยกจาก settings.set เพราะการบันทึกค่าลงฐานข้อมูลกับการเขียนไฟล์ค่าตั้งของ
 * บริการระบบเป็นคนละความเสี่ยงกัน อย่างหลังต้องผ่าน ConfigTransaction
 * และ `postfix check` ก่อนมีผลจริง
 */
final class MailApply implements Capability
{
    public static function name(): string
    {
        return 'mail.apply';
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
        return 'เขียนค่าตั้งเมลขาออกลง Postfix';
    }

    public function validate(array $args): array
    {
        return ['hostname' => trim((string) ($args['hostname'] ?? ''))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new MailManager(new Template($context->config->paths->templates()));

        $hostname = $args['hostname'];

        if ($hostname === '') {
            // ใช้ส่วนโดเมนของที่อยู่ผู้ส่งเป็นชื่อโฮสต์ ถ้าผู้ใช้ไม่ได้ระบุมา
            $from = $settings->get('mail.from');
            $hostname = $from !== '' && str_contains($from, '@')
                ? substr($from, strpos($from, '@') + 1)
                : (gethostname() ?: 'localhost');
        }

        $result = $manager->apply($executor, [
            'mode' => $settings->get('mail.mode'),
            'hostname' => $hostname,
            'from' => $settings->get('mail.from'),
            'relay_host' => $settings->get('mail.relay_host'),
            'relay_port' => $settings->int('mail.relay_port'),
            'relay_user' => $settings->get('mail.relay_user'),
            'relay_password' => $settings->get('mail.relay_password'),
            'relay_tls' => $settings->bool('mail.relay_tls'),
        ]);

        return [
            'mode' => $result['mode'],
            'relay' => $result['relay'],
            'message' => $result['mode'] === 'relay'
                ? sprintf('ตั้งค่าให้ส่งเมลผ่าน %s เรียบร้อยแล้ว', $result['relay'])
                : 'ตั้งค่าให้ส่งเมลตรงจากเครื่องนี้เรียบร้อยแล้ว',
        ];
    }
}
