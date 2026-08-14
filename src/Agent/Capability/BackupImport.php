<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ดึงไฟล์สำรองจากปลายทางนอกเครื่องกลับเข้าโฟลเดอร์ของเจ้าของ
 *
 * ## ทำไมต้องมี
 *
 * วันที่เครื่องต้นทางพัง สำเนาที่อุตส่าห์ส่งไปเก็บไว้อีกเครื่องต้องเอากลับมาใช้ผ่าน panel
 * ได้ · ไม่งั้นมันเป็นแค่ `.tar.gz` ที่ต้องแตกเองแล้วไล่ตั้งเจ้าของไฟล์เอง ซึ่งเป็นงาน
 * ที่คนทำตอนตกใจแล้วพลาดได้ง่ายที่สุด — สำเนานอกเครื่องที่ **เขียนได้อย่างเดียว
 * อ่านกลับไม่ได้** กลับหัวกับเหตุผลที่มันมีอยู่
 *
 * ## ไฟล์ลงที่ไหน
 *
 * `<บ้านของเจ้าของ>/backup/` เหมือนไฟล์ที่สร้างบนเครื่องนี้เอง (ข้อ B5) — ไม่ใช่พื้นที่
 * ของ panel · เจ้าของคือเจ้าของ**เว็บที่ใบแจ้งข้อมูลระบุ** ไม่ใช่คนที่กดปุ่ม: ไฟล์ของ
 * shop.example.com ต้องไปอยู่ในบ้านของคนที่ดูแล shop.example.com เสมอ ไม่งั้นมันจะไป
 * กินโควตาของผู้ดูแลที่บังเอิญเป็นคนกด และคนที่ควรได้ไฟล์คืนก็ยังหาไม่เจอ
 *
 * ## ปลายทางรู้ได้อย่างไรว่าไฟล์นี้เป็นของใคร
 *
 * จาก `backup.json` ที่อยู่ที่รากของ archive ({@see BackupManager::writeManifest()}) —
 * อ่านออกมาได้โดยไม่ต้องแตกทั้งก้อน จึงรู้ว่าเป็นของโดเมนไหนก่อนตัดสินใจว่าจะวางที่ไหน
 * · ดึงลงที่พักของ panel ก่อนแล้วค่อยย้ายเข้าบ้าน เพราะตอนเริ่มดึงยังไม่มีใครรู้ว่า
 * บ้านไหนคือบ้านที่ถูก และการเดาแล้วย้ายทีหลังแปลว่าโควตาของคนที่เดาถูกหักไปแล้ว
 *
 * ## สิ่งที่จงใจ**ไม่**ทำ
 *
 * ไม่กู้คืนให้อัตโนมัติ · จบที่การวางไฟล์ไว้ในโฟลเดอร์ แล้วให้ผู้ดูแลกดกู้คืนเอง ซึ่ง
 * ต้องพิมพ์ชื่อโดเมนยืนยันตามเดิม — "ดึงไฟล์จากที่เก็บ" กับ "เขียนทับเว็บที่ใช้งานอยู่"
 * เป็นคนละการตัดสินใจ
 */
final class BackupImport extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.import';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ดึงไฟล์สำรองจากปลายทางนอกเครื่องกลับเข้าโฟลเดอร์ของเจ้าของ';
    }

    public function validate(array $args): array
    {
        return [
            'destination_id' => Validator::requireInt($args, 'destination_id', 1),
            'remote_name' => Validator::requireString($args, 'remote_name', 255),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!self::isAdmin($context->actor->role) && $context->actor->userId !== 0) {
            throw new PermissionDenied('การดึงไฟล์สำรองจากนอกเครื่องต้องใช้สิทธิ์ผู้ดูแลเซิร์ฟเวอร์');
        }

        /*
         * ชื่อไฟล์ล้วนเท่านั้น — ค่านี้ถูกต่อเข้ากับเส้นทางของปลายทาง การยอมให้มี `/`
         * หรือ `..` แปลว่าผู้เรียกเลือกได้ว่าจะให้ไปหยิบไฟล์ไหนบนเครื่องปลายทาง
         * (driver ตรวจซ้ำอีกชั้นด้วย assertInsidePath แต่ด่านแรกต้องอยู่ที่นี่)
         */
        $name = basename(trim($args['remote_name']));

        if ($name === '' || $name !== $args['remote_name'] || str_contains($name, '..')) {
            throw new ValidationError('ชื่อไฟล์ต้องเป็นชื่อล้วน ไม่มีเส้นทางนำหน้า');
        }

        $destinations = new BackupDestinationRepository($context->db, new Secret($context->config->secretKey()));
        $row = $destinations->find($args['destination_id']);

        if ($row === null) {
            throw new ValidationError('ไม่พบปลายทางที่ระบุ');
        }

        // ที่พักของ panel — ยังไม่ใช่ของใคร จนกว่าใบแจ้งข้อมูลจะบอกว่าไฟล์นี้เป็นของเว็บไหน
        $staging = $context->config->paths->backups();
        $local = $staging . '/imported-' . date('Ymd-His') . '-' . $name;

        $executor->makeDirectory($executor->path($staging), 0750);

        $destination = (new DestinationFactory($destinations, $staging))->make($row);
        $destination->pull($executor, $this->remotePath($row, $name), $local);

        try {
            return $this->deliver($executor, $context, $local, $name);
        } catch (\Throwable $e) {
            // ส่งถึงเจ้าของไม่ได้ = ไฟล์นี้ใช้อะไรไม่ได้ · ทิ้งที่พักไว้ก็มีแต่จะสับสน
            $executor->removePath($executor->path($local));

            throw $e;
        }
    }

    /**
     * ตรวจใบแจ้งข้อมูล หาเจ้าของ แล้วย้ายไฟล์เข้าบ้านของเขา
     *
     * @return array<string,mixed>
     */
    private function deliver(Executor $executor, Context $context, string $local, string $name): array
    {
        $manager = new BackupManager();
        $manifest = $manager->readManifest($executor, $local);

        if ($manifest === null) {
            throw new ValidationError(
                'ไฟล์นี้ไม่มี ' . BackupManager::MANIFEST . ' อยู่ข้างใน จึงบอกไม่ได้ว่าเป็นของเว็บไหน'
                . ' — ไฟล์สำรองที่สร้างก่อนระบบรองรับการนำเข้าจะเป็นแบบนี้ ให้สร้างใหม่จากเครื่องต้นทาง',
            );
        }

        if ((int) ($manifest['schema'] ?? 0) !== BackupManager::MANIFEST_SCHEMA) {
            throw new ValidationError(
                'รูปแบบใบแจ้งข้อมูลเป็นรุ่น ' . (int) ($manifest['schema'] ?? 0)
                . ' ซึ่งเครื่องนี้อ่านไม่เข้าใจ (รองรับรุ่น ' . BackupManager::MANIFEST_SCHEMA . ')',
            );
        }

        $domain = (string) ($manifest['domain'] ?? '');

        if ($domain === '') {
            throw new ValidationError('ใบแจ้งข้อมูลไม่ได้ระบุโดเมนต้นทาง');
        }

        /*
         * เว็บปลายทางต้องมีอยู่ก่อน — **ไม่สร้างให้อัตโนมัติ**
         *
         * การสร้างเว็บคือการเขียน vhost, FPM pool, บัญชีระบบ และ DNS ซึ่งขึ้นกับค่าตั้ง
         * ของเครื่องนี้ ไม่ใช่ของเครื่องต้นทาง · เดาแทนผู้ดูแลแล้วผิดจะได้เว็บที่ตั้งค่า
         * ครึ่ง ๆ กลาง ๆ ซึ่งหาสาเหตุยากกว่าข้อความว่า "ยังไม่มีเว็บนี้ สร้างก่อน"
         *
         * และเมื่อไม่มีเว็บ ก็ไม่มีบ้านที่จะวางไฟล์ลงไปด้วย
         */
        $site = (new SiteRepository($context->db))->findByDomain($domain);

        if ($site === null) {
            throw new ValidationError(
                'ไฟล์สำรองนี้เป็นของ ' . $domain . ' ซึ่งยังไม่มีอยู่บนเครื่องนี้'
                . ' — สร้างเว็บไซต์ชื่อนี้ก่อน แล้วค่อยนำเข้าอีกครั้ง',
            );
        }

        $owner = $this->ownerAccount($context, (int) $site['owner_user_id']);

        $this->assertQuotaAllows($context, $owner);

        $target = $owner->backupDir() . '/' . $this->localName($domain, $name);

        $executor->makeDirectory($executor->path($owner->backupDir()), 0750);
        $executor->rename($executor->path($local), $executor->path($target));

        $ownerString = self::ownerString($context, $owner);

        if ($ownerString !== '') {
            $executor->exec(['/usr/bin/chown', $ownerString, $executor->path($target)], timeout: 60);
            $executor->changeMode($executor->path($target), 0640);
        }

        $stat = $executor->stat($executor->path($target));

        if ($stat === null) {
            throw new ExecutionFailed('ย้ายไฟล์เข้าโฟลเดอร์ของเจ้าของแล้วแต่หาไฟล์ไม่พบ');
        }

        return [
            'domain' => $domain,
            'site_id' => (int) $site['id'],
            'user_id' => $owner->userId,
            'file' => basename($target),
            'source_host' => (string) ($manifest['hostname'] ?? ''),
            'created_at' => (int) ($manifest['created_at'] ?? 0),
            'bytes' => (int) $stat['size'],
            'message' => sprintf(
                'นำเข้าไฟล์สำรองของ %s ไปไว้ที่ %s แล้ว (จาก %s · %s) — กดกู้คืนเพื่อเขียนทับเว็บนี้',
                $domain,
                $owner->backupDir(),
                (string) ($manifest['hostname'] ?? 'ไม่ทราบ'),
                date('j/n/Y H:i', (int) ($manifest['created_at'] ?? time())),
            ),
        ];
    }

    /**
     * ชื่อไฟล์ในโฟลเดอร์ของเจ้าของ
     *
     * ขึ้นต้นด้วยโดเมนเหมือนไฟล์ที่สร้างบนเครื่องนี้ เพื่อให้รายการที่อ่านจากโฟลเดอร์
     * จับคู่ไฟล์กับเว็บได้ ({@see \Phpcp\Domain\BackupFiles::domainOf()}) — ชื่อที่ปลายทาง
     * เป็นของเครื่องอื่น จะเป็นอะไรก็ได้ · ต่อท้ายด้วยเวลาเพื่อไม่ให้นำเข้าซ้ำแล้วทับของเดิม
     */
    private function localName(string $domain, string $remote): string
    {
        // ชนิดต้องตรงกับนามสกุล ไม่ใช่ตั้งเป็น "files" ไว้ก่อน — รายการอ่านชนิดจาก
        // นามสกุล ส่วนคนอ่านชื่อไฟล์อ่านจากคำกลาง สองอย่างนี้ขัดกันไม่ได้
        $isDatabase = str_ends_with($remote, BackupFiles::DB_SUFFIX);

        return sprintf(
            '%s-%s-imported-%s-%s%s',
            $domain,
            $isDatabase ? 'db' : 'files',
            date('Ymd-His'),
            bin2hex(random_bytes(3)),
            $isDatabase ? BackupFiles::DB_SUFFIX : BackupFiles::SITE_SUFFIX,
        );
    }

    /**
     * เส้นทางเต็มของไฟล์ที่ปลายทาง
     *
     * ประกอบจากค่าตั้งของปลายทางเอง ไม่ใช่รับมาจากผู้เรียก — ผู้เรียกระบุได้แค่ชื่อไฟล์
     *
     * @param array<string,mixed> $row
     */
    private function remotePath(array $row, string $name): string
    {
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        $base = rtrim((string) ($config['path'] ?? ''), '/');

        return $base === '' ? $name : $base . '/' . $name;
    }
}
