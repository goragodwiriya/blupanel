<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * อ่านไฟล์ตั้งค่าของ BIND9 — คู่กับ `config.file_read` และ `mail.config_read`
 *
 * **อ่านได้แม้ `dns.enabled` จะปิดอยู่** ต่างจากการเขียนซึ่งต้องเปิดก่อน — ตอนที่ผู้ดูแล
 * อยากดูไฟล์มากที่สุดคือตอนที่ DNS ไม่ทำงาน และการซ่อนไฟล์ตอนนั้นคือการซ่อนคำตอบ
 * ในนาทีที่ต้องการมันที่สุด · การอ่านไม่เปลี่ยนอะไรบนเครื่องอยู่แล้ว
 */
final class DnsConfigRead implements Capability
{
    public static function name(): string
    {
        return 'dns.config_read';
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
        return 'อ่านไฟล์ตั้งค่าของ BIND9';
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
        $files = ConfigFileCatalog::forDns(
            BindZoneManager::customConfigPath($context->config),
            [$context->config->dnsNamedConfLocal()],
        );

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
