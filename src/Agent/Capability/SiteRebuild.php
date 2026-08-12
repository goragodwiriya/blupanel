<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;

/**
 * สร้างไฟล์ตั้งค่าของทุกเว็บไซต์ใหม่ตามค่า `webserver` ปัจจุบัน
 *
 * **จำเป็นตอนเปลี่ยนเว็บเซิร์ฟเวอร์** — ไฟล์ vhost ที่มีอยู่เป็นรูปแบบของตัวเก่า
 * เปลี่ยนค่าใน config.php เฉย ๆ ไม่ทำให้อะไรเกิดขึ้นเลยจนกว่าจะเขียนไฟล์ใหม่
 * (`etc/config.example.php` บอกให้รัน `phpcp sites:rebuild` มาตั้งแต่ต้น
 * แต่ไม่เคยมีคำสั่งนี้อยู่จริง — เอกสารสัญญาไว้โดยไม่มีโค้ดรองรับ)
 *
 * **สองอย่างที่ทำให้ไม่ใช่แค่ "เขียนไฟล์วนลูป":**
 *
 *   1. ต้องเก็บกวาดไฟล์ของเซิร์ฟเวอร์ตัวเก่า — ย้ายจาก apache ไป nginx แล้วปล่อย
 *      `/etc/apache2/sites-enabled/phpcp-*.conf` ค้างไว้ = Apache ยังเสิร์ฟเว็บเดิม
 *      อยู่คู่ขนานด้วย config ที่ panel ไม่ดูแลแล้ว · ลบเฉพาะไฟล์ที่ขึ้นต้นด้วย
 *      `phpcp-` เท่านั้น ของที่ผู้ดูแลเขียนเองไม่แตะ
 *   2. ทั้งหมดอยู่ในทรานแซกชันเดียว — ครึ่ง ๆ กลาง ๆ แปลว่าบางเว็บชี้ไปเซิร์ฟเวอร์
 *      ที่ไม่ได้รับคำขอแล้ว ถ้า configtest ไม่ผ่านต้องกลับไปสภาพเดิมทั้งหมด
 */
final class SiteRebuild extends SiteCapability
{
    /** ไฟล์ที่ panel เป็นเจ้าของในไดเรกทอรีของแต่ละเซิร์ฟเวอร์ */
    private const OWNED_PREFIX = 'phpcp-';

    /** @var array<string,string> ไดเรกทอรี vhost ของทุกเซิร์ฟเวอร์ที่ระบบรองรับ */
    private const VHOST_DIRS = [
        'apache' => '/etc/apache2/sites-enabled',
        'nginx' => '/etc/nginx/conf.d',
    ];

    public static function name(): string
    {
        return 'site.rebuild';
    }

    /**
     * ไม่ใช่ `site.edit` ทั้งที่ชื่อขึ้นต้นด้วย site — เหตุผลเดียวกับที่ `dns.reload`
     * ไม่ใช้ `domain.manage`: คำสั่งนี้เขียนทับไฟล์ตั้งค่าของ **ลูกค้าทุกราย** พร้อมกัน
     * และแตะไฟล์ที่ทั้งเครื่องใช้ร่วมกัน (`ports.conf` ในโหมด nginx-proxy)
     *
     * ใช้ `settings.manage` เพราะเป็นงานที่ตามมาจากการแก้ค่า `webserver` ในไฟล์ตั้งค่า
     * โดยตรง — และเป็นสิทธิ์ของ superadmin เท่านั้นอยู่แล้ว
     */
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
        return 'สร้างไฟล์ตั้งค่าของทุกเว็บไซต์ใหม่';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);
        $webserver = $provisioner->webserver();

        $sites = $repository->listWithCounts();
        $transaction = new ConfigTransaction($executor);

        $stale = $this->staleFiles($executor, $webserver->name());
        foreach ($stale as $path) {
            $transaction->delete($path);
        }

        $rebuilt = [];
        $last = null;

        foreach ($sites as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
            );

            $rebuilt[] = $site->domain;
            $last = $site;
        }

        // ไฟล์กลางของโหมดต้องถูกเขียนเสมอ ไม่ว่าจะมีเว็บกี่เว็บหรือโหมดไหน
        //
        // คำสั่งนี้คือทางกลับของการเปลี่ยนโหมด — สิ่งที่โหมดก่อนหน้าเขียนทับไว้ต้องถูก
        // เขียนคืนที่นี่ · ตอนที่เขียนเฉพาะ nginx-proxy การสลับกลับมา apache ทิ้ง
        // `ports.conf` ไว้ที่ 127.0.0.1:8080 แล้วทั้งเครื่องไม่มีใครฟังพอร์ต 80 อีกเลย
        foreach ($webserver->globalFiles() as $path => $contents) {
            $transaction->write($path, $contents, 0644);
        }

        $transaction->commit(static fn (): array => $webserver->testConfig($executor));

        // reload ต้องมาหลัง commit เสมอ — ค่าที่ยังไม่ผ่าน configtest ต้องไม่มีวันถูกโหลด
        if ($last !== null) {
            $provisioner->reload($executor, $last);
        } else {
            $webserver->reload($executor);
        }

        return [
            'webserver' => $webserver->name(),
            'rebuilt' => $rebuilt,
            'count' => count($rebuilt),
            'removed_stale' => array_values($stale),
            'message' => sprintf(
                'สร้างไฟล์ตั้งค่าใหม่ให้ %d เว็บไซต์ตามรูปแบบของ %s แล้ว%s',
                count($rebuilt),
                $webserver->name(),
                $stale === [] ? '' : sprintf(' · เก็บกวาดไฟล์ของเซิร์ฟเวอร์ตัวเก่า %d ไฟล์', count($stale)),
            ),
        ];
    }

    /**
     * ไฟล์ของ panel ที่อยู่ในไดเรกทอรีของเซิร์ฟเวอร์ที่ **ไม่ได้ใช้แล้ว**
     *
     * โหมด nginx-proxy ใช้ทั้งสองไดเรกทอรีจึงไม่มีอะไรค้าง — คืนรายการว่างเสมอ
     *
     * @return list<string>
     */
    private function staleFiles(Executor $executor, string $webserver): array
    {
        $keep = match ($webserver) {
            'apache' => ['apache'],
            'nginx' => ['nginx'],
            default => ['apache', 'nginx'],
        };

        $stale = [];

        foreach (self::VHOST_DIRS as $server => $dir) {
            if (in_array($server, $keep, true)) {
                continue;
            }

            $resolved = $executor->path($dir);

            if (!$executor->exists($resolved)) {
                continue;
            }

            foreach ($executor->listDirectory($resolved) as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;

                if (is_string($name) && str_starts_with($name, self::OWNED_PREFIX) && str_ends_with($name, '.conf')) {
                    $stale[] = $dir . '/' . $name;
                }
            }
        }

        return $stale;
    }
}
