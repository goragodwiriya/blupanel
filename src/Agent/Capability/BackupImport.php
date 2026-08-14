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
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Backup\DestinationFactory;
use Phpcp\Driver\BackupManager;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * ดึงไฟล์สำรองจากปลายทางนอกเครื่องกลับมาลงทะเบียนบนเครื่องนี้
 *
 * ## ทำไมต้องมี
 *
 * `backup.push` ส่งไฟล์ออกไปแล้วบันทึกผลลง `backups` **ของเครื่องต้นทาง** ส่วน
 * `backup.restore` เริ่มจากแถวใน `backups` **ของเครื่องที่กู้** · ไฟล์ที่ไปนอนอยู่อีก
 * เครื่องจึงเป็นแค่ `.tar.gz` ที่ panel ที่นั่นไม่รู้จัก และไม่มีทางทำให้รู้จักได้เลย
 *
 * ผลคือสำเนานอกเครื่อง **เขียนได้อย่างเดียว อ่านกลับไม่ได้** — ซึ่งกลับหัวกับเหตุผล
 * ที่มันมีอยู่: วันที่เครื่องต้นทางพัง สำเนาที่อุตส่าห์ส่งไปเก็บกลับใช้ผ่าน panel ไม่ได้
 * ต้องแตก tar เองแล้วไล่ตั้งเจ้าของไฟล์เอง ซึ่งเป็นงานที่คนทำตอนตกใจแล้วพลาดได้ง่ายที่สุด
 *
 * ## ปลายทางรู้ได้อย่างไรว่าไฟล์นี้เป็นของใคร
 *
 * จาก `backup.json` ที่อยู่ที่รากของ archive ({@see BackupManager::writeManifest()}) —
 * อ่านออกมาได้โดยไม่ต้องแตกทั้งก้อน จึงรู้ว่าเป็นของโดเมนไหนก่อนตัดสินใจว่าจะทับที่ไหน
 *
 * ## สิ่งที่จงใจ**ไม่**ทำ
 *
 * ไม่กู้คืนให้อัตโนมัติ · จบที่การลงทะเบียนเท่านั้น แล้วให้ผู้ดูแลกดกู้คืนเอง ซึ่งต้อง
 * พิมพ์ชื่อโดเมนยืนยันตามเดิม — "ดึงไฟล์จากที่เก็บ" กับ "เขียนทับเว็บที่ใช้งานอยู่"
 * เป็นคนละการตัดสินใจ และการรวบสองอย่างเข้าด้วยกันแปลว่าพิมพ์ชื่อไฟล์ผิดครั้งเดียว
 * เว็บที่ยังดีอยู่ก็หายไปแล้ว
 */
final class BackupImport implements Capability
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
        return 'ดึงไฟล์สำรองจากปลายทางนอกเครื่องกลับมาลงทะเบียน';
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
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)
            && $context->actor->userId !== 0) {
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

        $manager = new BackupManager($context->config->paths->backups());
        $destination = (new DestinationFactory($destinations, $context->config->paths->backups()))->make($row);

        $local = $context->config->paths->backups() . '/imported-' . date('Ymd-His') . '-' . $name;

        $executor->makeDirectory($executor->path($context->config->paths->backups()), 0750);
        $destination->pull($executor, $this->remotePath($row, $name), $local);

        try {
            return $this->register($executor, $context, $manager, $local, $name, (int) $row['id']);
        } catch (\Throwable $e) {
            // ลงทะเบียนไม่ได้ = ไฟล์นี้ใช้อะไรไม่ได้ · เก็บไว้ก็มีแต่จะสับสนว่ามีของ
            $executor->removePath($executor->path($local));

            throw $e;
        }
    }

    /**
     * ตรวจใบแจ้งข้อมูล หาเว็บปลายทาง แล้วบันทึกเป็นไฟล์สำรองของเครื่องนี้
     *
     * @return array<string,mixed>
     */
    private function register(
        Executor $executor,
        Context $context,
        BackupManager $manager,
        string $local,
        string $name,
        int $destinationId,
    ): array {
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
         */
        $site = (new SiteRepository($context->db))->findByDomain($domain);

        if ($site === null) {
            throw new ValidationError(
                'ไฟล์สำรองนี้เป็นของ ' . $domain . ' ซึ่งยังไม่มีอยู่บนเครื่องนี้'
                . ' — สร้างเว็บไซต์ชื่อนี้ก่อน แล้วค่อยนำเข้าอีกครั้ง',
            );
        }

        $resolved = $executor->path($local);
        $stat = $executor->stat($resolved);
        $checksum = @hash_file('sha256', $resolved);

        if ($stat === null || $checksum === false) {
            throw new ExecutionFailed('ดึงไฟล์มาแล้วแต่อ่านไม่ได้');
        }

        $label = 'นำเข้าจากนอกเครื่อง — ' . $domain
            . ' (' . (string) ($manifest['hostname'] ?? 'ไม่ทราบเครื่องต้นทาง') . ')';

        $id = $context->db->insert('backups', [
            'name' => $label,
            'type' => 'site',
            'site_id' => (int) $site['id'],
            'path' => $local,
            'size_bytes' => (int) $stat['size'],
            'checksum' => $checksum,
            'status' => 'ok',
            // มาจากปลายทางไหน — ผู้ดูแลต้องตามรอยได้ว่าของก้อนนี้มาจากที่เก็บอันไหน
            'destination_id' => $destinationId,
            'remote_path' => $name,
            'offsite_status' => 'ok',
            'offsite_at' => time(),
            'created_at' => time(),
        ]);

        return [
            'id' => $id,
            'domain' => $domain,
            'site_id' => (int) $site['id'],
            'source_host' => (string) ($manifest['hostname'] ?? ''),
            'created_at' => (int) ($manifest['created_at'] ?? 0),
            'bytes' => (int) $stat['size'],
            'message' => sprintf(
                'นำเข้าไฟล์สำรองของ %s แล้ว (จาก %s · %s) — กดกู้คืนเพื่อเขียนทับเว็บนี้',
                $domain,
                (string) ($manifest['hostname'] ?? 'ไม่ทราบ'),
                date('j/n/Y H:i', (int) ($manifest['created_at'] ?? time())),
            ),
        ];
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
