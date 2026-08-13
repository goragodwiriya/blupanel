<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * อ่านค่าตั้งของเว็บไซต์ — ทั้งไฟล์ที่ panel สร้างและไฟล์ที่ผู้ดูแลเขียนเอง
 *
 * **ไฟล์ที่ panel สร้างเปิดดูได้แต่แก้ไม่ได้ และนั่นคือความตั้งใจ**
 *
 * ผู้ดูแลที่จะเขียนค่าเพิ่มต้องเห็นก่อนว่าตอนนี้มีอะไรอยู่แล้วบ้าง ไม่งั้นจะเขียนทับ
 * สิ่งที่มีอยู่โดยไม่รู้ตัว หรือเขียนซ้ำสิ่งที่ระบบตั้งให้อยู่แล้ว · การเปิดให้ดูจึง
 * ตอบโจทย์นั้นเต็ม ๆ โดยไม่สร้างกับดัก "แก้แล้วหายทีหลัง" ที่การเปิดให้แก้จะสร้างขึ้น
 */
final class SiteConfigRead extends SiteCapability
{
    public static function name(): string
    {
        return 'site.config_read';
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
        return 'อ่านไฟล์ตั้งค่าของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->loadSite($context, $args['site_id']);
        $templates = new Template($context->config->paths->templates());
        $driver = self::webServer($context, $templates);
        $server = $driver->name() === 'nginx' ? 'nginx' : 'apache';

        $generated = [];

        /*
         * อ่านจากไฟล์บนดิสก์จริง ไม่ใช่เรนเดอร์เทมเพลตใหม่มาแสดง — สองอย่างนี้ต่างกันได้
         * และเวลาที่ต่างกันคือเวลาที่ผู้ดูแลต้องการคำตอบมากที่สุด ("ทำไมค่าที่เห็นในหน้าจอ
         * ไม่ตรงกับที่เซิร์ฟเวอร์ทำอยู่")
         */
        foreach ($driver->vhostPaths($site) as $path) {
            $resolved = $executor->path($path);

            if (!$executor->exists($resolved)) {
                continue;
            }

            try {
                $generated[] = ['path' => $path, 'content' => $executor->readFile($resolved)];
            } catch (\Throwable) {
                $generated[] = ['path' => $path, 'content' => '(อ่านไฟล์ไม่ได้)'];
            }
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'server' => $driver->name(),
            'custom_path' => CustomConfig::path($server, $site->domain),
            'custom' => (new CustomConfig())->read($executor, $server, $site->domain),
            'generated' => $generated,
        ];
    }
}
