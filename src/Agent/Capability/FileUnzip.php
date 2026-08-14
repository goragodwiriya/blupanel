<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * แตกไฟล์บีบอัดในโฟลเดอร์ — `.zip`, `.tar.gz`/`.tgz`/`.tar` และ `.gz` ไฟล์เดี่ยว
 *
 * ## ทำไมต้องรองรับมากกว่า zip
 *
 * ไฟล์สำรองของระบบเป็น `.tar.gz` (และ `.sql.gz` สำหรับฐานข้อมูล) ซึ่งอยู่ในบ้านของ
 * ลูกค้าตั้งแต่ PLAN-BACKUP-V2 · ถ้าแตกผ่านหน้าเว็บไม่ได้ ลูกค้าที่อยากได้ไฟล์เดียว
 * คืนจากสำเนาเมื่อวาน ต้องกู้คืนทั้งเว็บทับของปัจจุบัน ซึ่งเป็นการใช้ค้อนทุบมด —
 * และเป็นคำสั่งที่อันตรายที่สุดในระบบ
 *
 * ## การกันเส้นทางไต่ออกนอกโฟลเดอร์
 *
 * zip: อยู่ที่ชั้น executor (`safeEntryPath`) ชื่อรายการไม่เคยผ่านมือ capability เลย
 *
 * tar: **ตรวจรายชื่อข้างในก่อนแตะดิสก์** ด้วย `--list` แล้วปฏิเสธทั้งไฟล์ถ้ามีรายการ
 * ที่ขึ้นต้นด้วย `/` หรือมี `..` · GNU tar ตัดสองอย่างนี้ทิ้งให้เองอยู่แล้วในทางปฏิบัติ
 * แต่นั่นเป็นพฤติกรรมของเครื่องมือที่เราไม่ได้ควบคุม — ด่านของเราต้องเป็นของเราเอง
 * (บทเรียนเดียวกับ `--exclude backup.json` ใน BackupManager::restoreSite)
 *
 * ปฏิเสธทั้งไฟล์ ไม่ใช่ข้ามรายการ เพราะ archive ที่มีรายการแบบนั้นคือ archive ที่
 * ตั้งใจร้าย ไม่ใช่ archive ปกติที่บังเอิญมีไฟล์แปลก ๆ ปนมาหนึ่งไฟล์
 */
final class FileUnzip extends FileCapability
{
    private const TAR = '/usr/bin/tar';
    private const GZIP = '/usr/bin/gzip';

    /** เพดานจำนวนรายการใน tar — ชุดเดียวกับที่ executor ใช้กับ zip */
    private const MAX_ENTRIES = 10_000;

    /** นามสกุลที่เป็น tar (บีบหรือไม่บีบก็ตาม) → tar อ่านออกเองจากเนื้อไฟล์ */
    private const TAR_SUFFIXES = ['.tar.gz', '.tgz', '.tar'];

    public static function name(): string
    {
        return 'file.unzip';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'แตกไฟล์บีบอัด (.zip, .tar.gz, .gz)';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์บีบอัดที่จะแตก');
        }

        if (self::kindOf($base['path']) === null) {
            throw new ValidationError('รองรับเฉพาะไฟล์ .zip, .tar.gz, .tgz, .tar และ .gz');
        }

        return $base + [
            // ค่าว่างหมายถึงแตกลงโฟลเดอร์เดียวกับที่ไฟล์บีบอัดอยู่
            'destination' => Validator::optionalString($args, 'destination') !== ''
                ? PathGuard::name((string) $args['destination'], 'ชื่อโฟลเดอร์ปลายทาง')
                : ''
        ];
    }

    /**
     * ชนิดของไฟล์จากนามสกุล — null = แตกไม่ได้
     *
     * `.tar.gz` ต้องถูกตรวจก่อน `.gz` เสมอ ไม่งั้นไฟล์สำรองของเว็บจะถูกมองเป็น
     * "ไฟล์เดียวที่บีบอัด" แล้วได้ `.tar` กองไว้แทนที่จะได้โฟลเดอร์ของเว็บกลับมา
     */
    public static function kindOf(string $path): ?string
    {
        $name = mb_strtolower($path);

        if (str_ends_with($name, '.zip')) {
            return 'zip';
        }

        foreach (self::TAR_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return 'tar';
            }
        }

        return str_ends_with($name, '.gz') ? 'gz' : null;
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     * @return mixed
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $archive = $args['path'];
        $kind = self::kindOf($archive);
        $folder = PathGuard::parent($archive);

        /*
         * `.gz` ไฟล์เดี่ยวไม่มี "โฟลเดอร์ปลายทาง" — มันคลายออกมาเป็นไฟล์ข้าง ๆ ตัวเอง
         * (`x.sql.gz` → `x.sql`) · การสร้างโฟลเดอร์ให้มันจะได้โฟลเดอร์ที่มีไฟล์เดียว
         * ซึ่งไม่ใช่สิ่งที่ใครคาดหวังจากการกด "แตกไฟล์" บนไฟล์ dump
         */
        $destination = $kind === 'gz' || $args['destination'] === ''
            ? $folder
            : PathGuard::join($folder, $args['destination']);

        $result = $this->withSite($executor, $scope, function (callable $resolve) use ($executor, $archive, $destination, $kind): array {
            $source = $resolve($archive);

            if (($executor->stat($source)['type'] ?? '') !== 'file') {
                throw new ValidationError('ไม่พบไฟล์บีบอัดที่ระบุ');
            }

            // สร้างโฟลเดอร์ปลายทางก่อนแล้วค่อย resolve ซ้ำ — resolve ต้องได้เส้นทาง
            // ที่คลาย symlink แล้วจริง ๆ ซึ่งทำได้ต่อเมื่อโฟลเดอร์มีอยู่บนดิสก์แล้ว
            $prepared = $resolve($destination, false);
            if ($executor->stat($prepared) === null) {
                $executor->makeDirectory($prepared, 0o750);
            }

            $target = $resolve($destination);

            return match ($kind) {
                'zip' => $executor->unzip($source, $target),
                'tar' => $this->extractTar($executor, $source, $target),
                default => $this->extractGz($executor, $source),
            };
        });

        $message = sprintf('แตกไฟล์ %d รายการแล้ว', $result['entries']);
        if ($result['skipped'] > 0) {
            $message .= sprintf(' (ข้าม %d รายการที่ไม่ปลอดภัย)', $result['skipped']);
        }

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'archive' => $archive,
            'destination' => $destination,
            'entries' => $result['entries'],
            'skipped' => $result['skipped'],
            'bytes' => $result['bytes'],
            'message' => $message
        ];
    }

    /**
     * แตก tar หลังตรวจรายชื่อข้างในแล้ว
     *
     * `--no-same-owner`/`--no-same-permissions` ใส่ไว้เสมอแม้จะทำงานในสิทธิ์ของลูกค้า
     * อยู่แล้ว (ดู `withSite`) — เจ้าของกับสิทธิ์ที่ฝังใน archive เป็นของ**เครื่องต้นทาง**
     * ซึ่ง uid ไม่ตรงกับเครื่องนี้ · บทเรียนเดียวกับที่ `BackupManager::restoreSite()`
     * ต้อง chown เองหลังแตกไฟล์
     *
     * @return array{entries:int,skipped:int,bytes:int}
     */
    private function extractTar(Executor $executor, string $source, string $destination): array
    {
        if (!$executor->exists(self::TAR)) {
            throw new ExecutionFailed('เครื่องนี้ไม่มีคำสั่ง tar จึงแตกไฟล์ชนิดนี้ไม่ได้');
        }

        $list = $executor->exec([self::TAR, '--list', '--file', $source], timeout: 120);

        if (!$list->ok()) {
            throw new ExecutionFailed('อ่านรายชื่อในไฟล์บีบอัดไม่ได้: ' . trim($list->stderr));
        }

        $entries = array_values(array_filter(array_map('trim', explode("\n", $list->stdout)), static fn (string $l): bool => $l !== ''));

        if (count($entries) > self::MAX_ENTRIES) {
            throw new ExecutionFailed('ไฟล์บีบอัดมีรายการมากเกินกำหนด');
        }

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '/') || in_array('..', explode('/', $entry), true)) {
                throw new ExecutionFailed(
                    'ไฟล์บีบอัดนี้มีรายการที่ชี้ออกนอกโฟลเดอร์ปลายทาง (' . $entry . ') จึงไม่แตกให้',
                );
            }
        }

        $result = $executor->exec([
            self::TAR,
            '--extract', '--file', $source,
            '--directory', $destination,
            '--no-same-owner', '--no-same-permissions',
        ], timeout: 900);

        if (!$result->ok()) {
            throw new ExecutionFailed('แตกไฟล์ไม่สำเร็จ: ' . trim($result->stderr));
        }

        return ['entries' => count($entries), 'skipped' => 0, 'bytes' => 0];
    }

    /**
     * คลาย `.gz` ที่เป็นไฟล์เดียว (เช่น `.sql.gz` ของฐานข้อมูล)
     *
     * ใช้ `gzip -dk` ให้มันเขียนไฟล์ปลายทางเอง — ไม่ดึงเนื้อผ่าน PHP เพราะไฟล์ dump
     * ของฐานข้อมูลจริงมีขนาดระดับกิกะไบต์ การอ่านเข้าหน่วยความจำก่อนเขียนคือการทำให้
     * คำสั่งนี้ล้มด้วย memory_limit ในกรณีที่คนใช้มันจริง ๆ
     *
     * `-k` เก็บไฟล์ต้นฉบับไว้ — คนกด "แตกไฟล์" ไม่ได้ขอให้ลบสำเนาที่บีบไว้ทิ้ง
     *
     * @return array{entries:int,skipped:int,bytes:int}
     */
    private function extractGz(Executor $executor, string $source): array
    {
        if (!$executor->exists(self::GZIP)) {
            throw new ExecutionFailed('เครื่องนี้ไม่มีคำสั่ง gzip จึงคลายไฟล์ชนิดนี้ไม่ได้');
        }

        $target = substr($source, 0, -3);

        // ไม่เขียนทับของที่มีอยู่ — ผู้ใช้อาจแก้ไฟล์นั้นไปแล้วหลังคลายครั้งก่อน
        if ($executor->exists($target)) {
            throw new ValidationError('มีไฟล์ ' . basename($target) . ' อยู่แล้ว — เปลี่ยนชื่อหรือลบก่อนคลายไฟล์นี้');
        }

        $result = $executor->exec([self::GZIP, '--decompress', '--keep', $source], timeout: 900);

        if (!$result->ok()) {
            throw new ExecutionFailed('คลายไฟล์ไม่สำเร็จ: ' . trim($result->stderr));
        }

        $stat = $executor->stat($target);

        return ['entries' => 1, 'skipped' => 0, 'bytes' => (int) ($stat['size'] ?? 0)];
    }
}
