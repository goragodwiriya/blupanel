<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * อ่านไฟล์ตั้งค่าหนึ่งไฟล์จากทะเบียน — ตัวกลางที่ทุกหน้าจอเรียกใช้ได้
 *
 * รับ **คีย์** ไม่ใช่เส้นทาง · เส้นทางจริงถูกประกอบใน `ConfigFileCatalog` จากข้อมูลที่
 * ผ่านการตรวจแล้ว ผู้เรียกจึงขอไฟล์นอกทะเบียนไม่ได้เลยไม่ว่าจะส่งอะไรมา
 *
 * อ่านจาก**ไฟล์บนดิสก์จริง** ไม่ใช่เรนเดอร์เทมเพลตใหม่มาแสดง — สองอย่างนี้ต่างกันได้
 * และเวลาที่ต่างกันคือเวลาที่ผู้ดูแลต้องการคำตอบมากที่สุด ("ทำไมค่าที่เห็นในหน้าจอ
 * ไม่ตรงกับที่เซิร์ฟเวอร์ทำอยู่")
 */
final class ConfigFileRead extends SiteCapability
{
    public static function name(): string
    {
        return 'config.file_read';
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
        return 'อ่านไฟล์ตั้งค่าจากทะเบียน';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // ว่าง = ขอรายการทั้งหมด · มีค่า = ขอเนื้อไฟล์เดียว
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->loadSite($context, $args['site_id']);
        $driver = self::webServer($context, new Template($context->config->paths->templates()));

        $files = ConfigFileCatalog::forSite(
            $site->id,
            $site->domain,
            $driver->name(),
            $driver->vhostPaths($site),
        );

        // เติมสถานะของไฟล์จริงให้ทุกแถว — หน้าจอต้องแยก "ยังไม่มีไฟล์" ออกจาก "ว่าง" ได้
        foreach ($files as $index => $file) {
            $exists = $executor->exists($executor->path($file['path']));
            $files[$index]['exists'] = $exists;
            $files[$index]['size'] = $exists ? (int) (($executor->stat($executor->path($file['path']))['size']) ?? 0) : 0;
        }

        if ($args['key'] === '') {
            return ['site_id' => $site->id, 'domain' => $site->domain, 'files' => $files];
        }

        $file = ConfigFileCatalog::find($files, $args['key']);

        if ($file === null) {
            throw new ValidationError('ไม่พบไฟล์ตั้งค่านี้ในทะเบียน');
        }

        $content = $this->contents($executor, (string) $file['path']);

        /*
         * ไฟล์ที่ยังไม่เคยถูกเขียน → เปิดมาพร้อมเนื้อไฟล์ตั้งต้นที่มีคำอธิบายและตัวอย่าง
         * (คอมเมนต์ไว้ทั้งหมด) · ผู้ดูแลจึงไม่ต้องเปิดหน้าเปล่าแล้วเดาว่าเขียนอะไรได้บ้าง
         * และคำอธิบายติดไปกับไฟล์เสมอ ไม่ใช่อยู่แค่บนหน้าจอที่ปิดแล้วหาย
         */
        if ($content === '' && ($file['kind'] ?? '') === ConfigFileCatalog::KIND_WRITABLE) {
            $content = (new CustomConfig())->seed(
                new Template($context->config->paths->templates()),
                $driver->name() === 'nginx' ? 'nginx' : 'apache',
                $site->domain,
            );
        }

        return $file + ['content' => $content];
    }

    private function contents(Executor $executor, string $path): string
    {
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return '';
        }

        try {
            return $executor->readFile($resolved);
        } catch (\Throwable) {
            return '';
        }
    }
}
