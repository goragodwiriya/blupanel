<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * อ่านไฟล์ตั้งค่าของระบบเมล — คู่กับ `config.file_read` ของเว็บไซต์
 *
 * แยกเป็นคนละ capability เพราะขอบเขตต่างกันจริง ๆ: ของเว็บไซต์ต้องรู้ว่าเป็นเว็บไหน
 * ของเมลเป็นของทั้งเครื่อง · แต่รูปร่างคำตอบเหมือนกันทุกฟิลด์ หน้าจอจึงใช้ตารางกับ
 * Modal ตัวเดียวกันได้โดยไม่ต้องรู้ว่ากำลังดูขอบเขตไหนอยู่
 */
final class MailConfigRead implements Capability
{
    /** ไฟล์ที่ panel สร้างให้ระบบเมล — เปิดดูได้ แต่แก้ไม่ได้ */
    private const GENERATED = [
        '/etc/postfix/main.cf',
        MailboxManager::DOVECOT_CONF,
    ];

    public static function name(): string
    {
        return 'mail.config_read';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านไฟล์ตั้งค่าของระบบเมล';
    }

    public function validate(array $args): array
    {
        return [
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $files = ConfigFileCatalog::forMail(self::GENERATED);

        foreach ($files as $index => $file) {
            $exists = $executor->exists($executor->path($file['path']));
            $files[$index]['exists'] = $exists;
            $files[$index]['size'] = $exists
                ? (int) (($executor->stat($executor->path($file['path']))['size']) ?? 0)
                : 0;
        }

        if ($args['key'] === '') {
            return ['files' => $files];
        }

        $file = ConfigFileCatalog::find($files, $args['key']);

        if ($file === null) {
            throw new ValidationError('ไม่พบไฟล์ตั้งค่านี้ในทะเบียน');
        }

        $content = '';
        $resolved = $executor->path((string) $file['path']);

        if ($executor->exists($resolved)) {
            try {
                $content = $executor->readFile($resolved);
            } catch (\Throwable) {
                $content = '';
            }
        }

        // ยังไม่เคยเขียน → เปิดมาพร้อมคำอธิบายและตัวอย่างที่คอมเมนต์ไว้ทั้งหมด
        if ($content === '' && $file['kind'] === ConfigFileCatalog::KIND_WRITABLE) {
            $content = (new CustomConfig())->seed(
                new Template($context->config->paths->templates()),
                (string) $file['service'],
            );
        }

        return $file + ['content' => $content];
    }
}
