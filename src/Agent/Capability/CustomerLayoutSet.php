<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteLayout;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * เปลี่ยนรูปทรงไฟล์ของบัญชีหนึ่ง — **ย้ายไฟล์จริงให้ด้วย ไม่ใช่แค่เปลี่ยนค่า**
 *
 * ## ทำไมการเปลี่ยนค่าเฉย ๆ ใช้ไม่ได้
 *
 * เส้นทางของเว็บถูก**คำนวณ**จากเลย์เอาต์ทุกครั้งที่อ่าน ไม่ได้เก็บไว้ · การเปลี่ยน
 * `users.site_layout` อย่างเดียวจึงทำให้ทั้งระบบชี้ไปที่เส้นทางใหม่ทันทีในขณะที่ไฟล์
 * ยังอยู่ที่เดิม — เว็บที่ให้บริการอยู่จะ 404 ทันทีที่กดบันทึก และ log ของเว็บจะขาดหาย
 * โดยไม่มีข้อความผิดพลาดใด ๆ บอกว่าเกิดอะไรขึ้น
 *
 * ที่นี่จึงทำสามอย่างให้ครบในคำสั่งเดียว: ย้ายไฟล์ → เปลี่ยนค่า → เขียน vhost/pool ใหม่
 *
 * ## ลำดับและการย้อนกลับ
 *
 * ย้ายไฟล์ก่อน เพราะเป็นขั้นที่ย้อนยากที่สุดและต้องรู้ผลก่อนแตะอย่างอื่น · ทุกการย้าย
 * ถูกจำไว้ ถ้าขั้นใดล้มจะย้ายกลับทั้งหมดตามลำดับย้อน แล้วโยนข้อผิดพลาดออกไป
 * ไฟล์ตั้งค่าของเว็บเซิร์ฟเวอร์ใช้ {@see ConfigTransaction} ซึ่งคืนไฟล์เดิมให้เองอยู่แล้ว
 *
 * `rename()` ภายในบ้านเดียวกันอยู่บน filesystem เดียวกันเสมอ จึงเป็นการย้ายแบบอะตอม
 * ไม่ใช่การคัดลอกทีละไฟล์ที่ค้างกลางทางได้
 *
 * ## สิ่งที่ยังต้องระวังและบอกผู้เรียกให้รู้
 *
 * เว็บของบัญชีนี้จะหยุดให้บริการชั่วขณะระหว่างย้าย (เสี้ยววินาทีต่อเว็บ) และ
 * **เส้นทางในสคริปต์ของลูกค้าที่เขียนเส้นทางเต็มไว้จะพัง** — จึงต้องเป็นคำสั่งที่
 * ผู้ดูแลตั้งใจกด ไม่ใช่ผลข้างเคียงของการบันทึกฟอร์มอื่น
 */
final class CustomerLayoutSet extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.layout_set';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เปลี่ยนรูปทรงไฟล์ของบัญชีโฮสติ้ง พร้อมย้ายไฟล์ให้';
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        $layout = trim((string) ($args['layout'] ?? ''));

        // ค่าว่าง = ตามค่าเริ่มต้นของระบบ ซึ่งเป็นตัวเลือกที่ถูกต้อง ไม่ใช่การไม่ส่งค่า
        if ($layout !== '' && SiteLayout::tryFrom($layout) === null) {
            throw new ValidationError(
                'รูปทรงไฟล์ต้องเป็น phpcp, cpanel หรือค่าว่าง (ตามค่าเริ่มต้นของระบบ)',
            );
        }

        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'layout' => $layout,
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);
        $owner = UserAccount::fromRow($user);

        $target = $args['layout'] === '' ? null : SiteLayout::from($args['layout']);
        $effective = $target ?? SiteLayout::systemDefault();

        if ($owner->layout() === $effective && ($owner->layout ?? null) === $target) {
            return [
                'user_id' => $owner->userId,
                'layout' => $args['layout'],
                'moved' => 0,
                'message' => 'บัญชีนี้ใช้รูปทรงนี้อยู่แล้ว ไม่มีอะไรต้องเปลี่ยน',
            ];
        }

        $repository = new SiteRepository($context->db);
        $sites = $context->db->all(
            'SELECT id FROM sites WHERE owner_user_id = :u ORDER BY created_at, id',
            ['u' => $owner->userId],
        );

        // บัญชีที่ยังไม่มีเว็บไม่มีไฟล์ให้ย้าย — เปลี่ยนค่าแล้วจบ
        if ($sites === []) {
            $this->save($context, $owner->userId, $args['layout']);

            return [
                'user_id' => $owner->userId,
                'layout' => $args['layout'],
                'moved' => 0,
                'message' => sprintf('ตั้งรูปทรงไฟล์เป็น %s แล้ว (ยังไม่มีเว็บไซต์ จึงไม่ต้องย้ายไฟล์)', $effective->value),
            ];
        }

        $moved = $this->moveAll($executor, $repository, $owner, $effective, array_column($sites, 'id'));

        $this->save($context, $owner->userId, $args['layout']);

        // เขียน vhost/pool ใหม่หลังค่าถูกบันทึกแล้ว — ต้องอ่านเส้นทางใหม่จากฐานข้อมูล
        // ไม่ใช่จาก UserAccount ตัวเดิมที่ยังถือเลย์เอาต์เก่าอยู่ในหน่วยความจำ
        $rebuilt = $this->rebuild($executor, $context, array_column($sites, 'id'));

        return [
            'user_id' => $owner->userId,
            'layout' => $args['layout'],
            'moved' => count($moved),
            'sites' => $rebuilt,
            'paths' => $moved,
            'message' => sprintf(
                'เปลี่ยนรูปทรงไฟล์เป็น %s · ย้าย %d ไดเรกทอรีและเขียนค่าตั้งของ %d เว็บใหม่แล้ว',
                $effective->value,
                count($moved),
                count($rebuilt),
            ),
        ];
    }

    /**
     * ย้ายไดเรกทอรีของทุกเว็บไปยังรูปทรงใหม่ · ล้มที่ไหนย้ายกลับทั้งหมด
     *
     * @param  list<int> $siteIds
     * @return list<array{from:string,to:string}>
     */
    private function moveAll(
        Executor $executor,
        SiteRepository $repository,
        UserAccount $owner,
        SiteLayout $target,
        array $siteIds,
    ): array {
        /** @var list<array{from:string,to:string}> $done */
        $done = [];

        try {
            foreach ($siteIds as $siteId) {
                $site = $repository->load((int) $siteId);

                foreach ($this->plan($owner, $target, $site) as $from => $to) {
                    if (!$executor->exists($executor->path($from)) || $from === $to) {
                        continue;
                    }

                    if ($executor->exists($executor->path($to))) {
                        /*
                         * ไดเรกทอรีเปล่าที่ปลายทางคือเศษจากรอบที่ล้มไปก่อนหน้า — เกิดขึ้นเอง
                         * เพราะ `createDirectories()` สร้างโครงของเลย์เอาต์ใหม่ไว้ก่อนแล้ว
                         * · ปฏิเสธทั้งหมดในกรณีนี้แปลว่าผู้ดูแลต้อง ssh เข้าไปลบโฟลเดอร์เปล่า
                         * ด้วยมือก่อนถึงจะลองใหม่ได้ ซึ่งเป็นงานที่ระบบทำเองได้อย่างปลอดภัย
                         *
                         * ที่มี**ไฟล์อยู่ข้างใน**ยังปฏิเสธเหมือนเดิม — นั่นคือข้อมูลของใครบางคน
                         * และการเดาว่าทับได้คือการลบไฟล์ลูกค้าโดยที่ไม่มีใครสั่ง
                         */
                        if ($this->isEmptyDir($executor, $to)) {
                            $executor->removePath($executor->path($to));
                        } else {
                            throw new ExecutionFailed(
                                "ปลายทาง {$to} มีไฟล์อยู่แล้ว — ย้ายไม่ได้เพราะจะทับของเดิม\n\n"
                                . 'ตรวจว่าเป็นไฟล์ที่เหลือจากการย้ายครั้งก่อนหรือไม่ แล้วย้ายออกก่อนลองใหม่',
                            );
                        }
                    }

                    $executor->makeDirectory($executor->path(dirname($to)), 0750);
                    $executor->rename($executor->path($from), $executor->path($to));

                    $done[] = ['from' => $from, 'to' => $to];
                }
            }
        } catch (\Throwable $e) {
            // ย้อนตามลำดับกลับ — ไฟล์ต้องกลับไปอยู่ที่เดิมครบก่อนโยนข้อผิดพลาดออกไป
            foreach (array_reverse($done) as $step) {
                $executor->rename($executor->path($step['to']), $executor->path($step['from']));
            }

            throw new ExecutionFailed(
                "ย้ายไฟล์ไม่สำเร็จ จึงย้ายทุกอย่างกลับที่เดิมแล้ว ไม่มีเว็บใดเสียหาย\n\n"
                . $e->getMessage(),
            );
        }

        return $done;
    }

    /**
     * เส้นทางเก่า => เส้นทางใหม่ ของเว็บหนึ่งแห่ง
     *
     * เว็บที่ตั้ง Domain Pointer ไว้ไม่ย้าย docroot เพราะไฟล์อยู่นอกบ้านตามที่ผู้ดูแล
     * ตั้งใจ — แต่ log กับ backup ยังต้องย้ายตามรูปทรงใหม่
     *
     * @return array<string,string>
     */
    private function plan(UserAccount $owner, SiteLayout $target, Site $site): array
    {
        $home = $owner->home();
        $domain = $site->domain;
        $from = $owner->layout();
        $isMain = $owner->isMainDomain($domain);

        $children = [
            $from->logDir($home, $domain) => $target->logDir($home, $domain),
            $from->backupDir($home, $domain) => $target->backupDir($home, $domain),
        ];

        if ($site->docrootOverride === '') {
            $children[$from->docroot($home, $domain, $isMain)] = $target->docroot($home, $domain, $isMain);
        }

        $stateFrom = $from->stateDir($home, $domain);
        $stateTo = $target->stateDir($home, $domain);

        /*
         * ลำดับของ stateDir สลับทิศตามว่ามันเป็น "แม่" ของฝั่งไหน
         *
         * เลย์เอาต์ phpcp วาง log/backup/docroot ไว้**ใต้** stateDir ส่วน cpanel วางแยกกัน
         * · สองทิศทางจึงต้องการลำดับตรงข้ามกัน และการใช้ลำดับเดียวทั้งสองทางพังเสมอ
         * ในทิศใดทิศหนึ่ง:
         *
         *   phpcp → cpanel  ย้ายลูกออกก่อน แล้วค่อยย้ายแม่ที่เหลือ
         *                   (ย้ายแม่ก่อน = ลูกที่คำนวณไว้ชี้ไปยังที่ที่ไม่มีอยู่แล้ว)
         *
         *   cpanel → phpcp  ย้ายแม่ก่อน แล้วลูกจึงมีที่ลง
         *                   (ย้ายลูกก่อน = ระบบสร้างโฟลเดอร์แม่ให้เองเพื่อรองรับลูก
         *                    แล้วการย้ายแม่ตัวจริงถูกปฏิเสธเพราะปลายทางมีอยู่แล้ว
         *                    — เจอบนเซิร์ฟเวอร์จริงตอนย้ายกลับ 2026-08-13)
         */
        $stateIsParentOfTarget = self::isUnder(reset($children) ?: '', $stateTo);

        return $stateIsParentOfTarget
            ? [$stateFrom => $stateTo] + $children
            : $children + [$stateFrom => $stateTo];
    }

    /**
     * ไดเรกทอรีนี้ว่างเปล่าจริงหรือไม่ — ว่าง = ลบทิ้งได้โดยไม่ทำใครเสียหาย
     *
     * นับไฟล์ซ่อนด้วย · `listDirectory()` ของโปรเจกต์นี้คืนรายการที่ไม่รวม `.` กับ `..`
     * อยู่แล้ว รายการว่างจึงแปลว่าว่างจริง ไม่ใช่ว่างเพราะกรองผิด
     */
    private function isEmptyDir(Executor $executor, string $path): bool
    {
        try {
            return $executor->listDirectory($executor->path($path)) === [];
        } catch (\Throwable) {
            // อ่านไม่ได้ = ไม่รู้ว่าข้างในมีอะไร จึงต้องไม่ลบ
            return false;
        }
    }

    /** $path อยู่ใต้ $parent หรือไม่ (เทียบเป็นเส้นทาง ไม่ใช่เป็นข้อความ) */
    private static function isUnder(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');

        return $parent !== '' && str_starts_with(rtrim($path, '/').'/', $parent.'/');
    }

    /**
     * @param  list<int> $siteIds
     * @return list<string>
     */
    private function rebuild(Executor $executor, Context $context, array $siteIds): array
    {
        $repository = new SiteRepository($context->db);
        $provisioner = SiteCapability::provisionerFor($context);
        $transaction = new ConfigTransaction($executor);
        $rebuilt = [];
        $last = null;

        foreach ($siteIds as $siteId) {
            $site = $repository->load((int) $siteId);

            $provisioner->createDirectories($executor, $site);
            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
            );
            $provisioner->setOwnership($executor, $site);

            $rebuilt[] = $site->domain;
            $last = $site;
        }

        if ($last !== null) {
            $transaction->commit(static fn (): array => $provisioner->validate($executor, $last));
            $provisioner->reload($executor, $last);
        }

        // เส้นทางที่เก็บไว้ในคอลัมน์ต้องตรงกับความจริงใหม่ ไม่งั้นรายงานกับหน้าจอโกหก
        foreach ($siteIds as $siteId) {
            $site = $repository->load((int) $siteId);
            $context->db->update('sites', ['docroot' => $site->docroot(), 'updated_at' => time()], ['id' => (int) $siteId]);
        }

        return $rebuilt;
    }

    private function save(Context $context, int $userId, string $layout): void
    {
        $context->db->update(
            'users',
            ['site_layout' => $layout, 'updated_at' => time()],
            ['id' => $userId],
        );
    }
}
