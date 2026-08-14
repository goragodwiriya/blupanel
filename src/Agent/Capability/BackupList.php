<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupFiles;
use Phpcp\Domain\UserAccount;
use Phpcp\Support\Validator;

/**
 * รายการไฟล์สำรอง — อ่านจากโฟลเดอร์จริงในบ้านของลูกค้า
 *
 * ## ทำไมต้องผ่าน agent ทั้งที่เป็นแค่การอ่านรายชื่อไฟล์
 *
 * `<บ้าน>/backup` เป็นของลูกค้าโหมด 0750 — โปรเซสเว็บ (www-data) อ่านได้ก็ต่อเมื่อ
 * เจ้าของกลุ่มถูกตั้งไว้ถูกต้องแล้วเท่านั้น · บัญชีที่ยังไม่เคยถูก provision ใหม่ หรือ
 * เครื่องที่ตั้ง `shared_owner` ไว้ จะอ่านไม่ได้แล้วหน้าจอจะบอกว่า "ไม่มีไฟล์สำรอง"
 * ทั้งที่มีอยู่ครบ — คำตอบที่ผิดแบบที่ดูเหมือนคำตอบที่ถูก · agent เป็น root จึงเห็น
 * ตรงกับความจริงเสมอ ไม่ว่าเจ้าของไฟล์จะเป็นใคร (เหตุผลเดียวกับ `file.list`)
 */
final class BackupList extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.list';
    }

    public function permission(): string
    {
        return 'backup.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านรายการไฟล์สำรองจากโฟลเดอร์ของแต่ละบัญชี';
    }

    public function validate(array $args): array
    {
        return [
            // 0 = ทุกบัญชีที่ผู้เรียกมีสิทธิ์เห็น
            'user_id' => Validator::optionalInt($args, 'user_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $accounts = $args['user_id'] > 0
            ? [$this->ownerAccount($context, $args['user_id'])]
            : $this->visibleAccounts($context);

        $files = [];
        $bytes = 0;

        foreach ($accounts as $account) {
            foreach ($this->filesOf($executor, $context, $account) as $file) {
                $files[] = $file;
                $bytes += $file['bytes'];
            }
        }

        // ใหม่สุดขึ้นก่อนข้ามบัญชี — หน้าจอของผู้ดูแลเรียงรวมกันทั้งเครื่อง
        usort($files, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return [
            'files' => $files,
            'count' => count($files),
            'bytes' => $bytes,
        ];
    }

    /**
     * ไฟล์ของบัญชีเดียว พร้อมข้อมูลที่หน้าจอต้องใช้
     *
     * โฟลเดอร์ที่อ่านไม่ได้ต้องไม่ทำให้ทั้งรายการล้ม — บัญชีหนึ่งที่มีสิทธิ์เพี้ยนจะ
     * ทำให้ผู้ดูแลมองไม่เห็นไฟล์สำรองของบัญชีอื่นเลยทั้งเครื่อง ซึ่งแย่กว่าการเห็น
     * ไม่ครบหนึ่งบัญชี · ข้อผิดพลาดจึงติดไปกับแถวของบัญชีนั้นแทน
     *
     * @return list<array<string,mixed>>
     */
    private function filesOf(Executor $executor, Context $context, UserAccount $account): array
    {
        try {
            $files = BackupFiles::listFor($executor, $account, $this->domainsOf($context, $account->userId));
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $file): array => $file + [
                'user_id' => $account->userId,
                'username' => $account->username,
            ],
            $files,
        );
    }
}
