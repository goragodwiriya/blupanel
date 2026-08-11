<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ฐานข้อมูล MariaDB — `/api/v2/databases`
 *
 * ตัวระบุคือ **ชื่อฐานข้อมูล** ไม่ใช่ id ตัวเลข เพราะชื่อคือสิ่งที่ไม่ซ้ำจริงบน MariaDB
 * และเป็นสิ่งที่ผู้ดูแลใช้อ้างถึงมันทุกที่ (ใน mysql client, ในไฟล์ config ของเว็บ)
 * การใช้ id ของตาราง panel จะทำให้ API อ้างถึงสิ่งที่ MariaDB ไม่รู้จัก
 *
 * การแบ่งขอบเขตตามเจ้าของทำที่ capability `db.list` (ฝั่ง agent) อยู่แล้ว —
 * webadmin จะได้เฉพาะฐานข้อมูลของเว็บตัวเองกลับมา ชั้นนี้จึงไม่ต้องกรองซ้ำ
 */
final class DatabasesController extends ApiController
{
    public function index(Request $request): Response
    {
        $data = $this->agent()->data('db.list', [], $this->ctx->actor($request));
        $all = $data['databases'] ?? [];

        $query = $this->searchTerm($request);
        if ($query !== '') {
            $all = self::filter($all, $query);
        }

        // กรองด้วยชื่อฟิลด์ตรง ๆ ตาม §4.5 — หน้ารายละเอียดเว็บไซต์ใช้ตัวนี้เพื่อไม่ต้อง
        // ดึงฐานข้อมูลของลูกค้ารายอื่นมาทั้งหมดแล้วค่อยกรองทิ้งฝั่งหน้าเว็บ
        $siteId = $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $all = array_values(array_filter(
                $all,
                static fn (array $db): bool => (int) ($db['site_id'] ?? 0) === $siteId,
            ));
        }

        $page = $this->pagination($request);
        $total = count($all);
        $slice = array_slice($all, $page['offset'], $page['per_page']);

        // เงื่อนไขปุ่มลบในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        $manage = $this->ctx->can('db.manage');
        $slice = array_map(
            static fn (array $db): array => $db + ['can_manage' => $manage],
            $slice,
        );

        return $this->paginate(
            array_values($slice),
            $total,
            $page['page'],
            $page['per_page'],
        )->withHeader('X-Total-Size', (string) array_sum(array_map(
            static fn (array $db): int => (int) ($db['size'] ?? 0),
            $all,
        )));
    }

    /**
     * สร้างฐานข้อมูลพร้อมผู้ใช้
     *
     * รหัสผ่านที่สุ่มให้อยู่ในคำตอบ **ครั้งเดียวเท่านั้น** — panel ไม่เก็บไว้ที่ไหนเลย
     * (ของเดิมส่งผ่าน query string ตอน redirect ซึ่งไปโผล่ใน log ของเว็บเซิร์ฟเวอร์
     * และในประวัติเบราว์เซอร์ · การส่งใน body ของคำตอบไม่มีปัญหานั้น)
     */
    public function store(Request $request): Response
    {
        $result = $this->agent()->data('db.create', [
            'name' => trim($request->payloadString('name')),
            'username' => trim($request->payloadString('username')),
            'host' => $request->payloadString('host') ?: 'localhost',
            'privileges' => $request->payloadString('privileges') ?: 'readwrite',
            'site_id' => (int) $request->payload('site_id', 0),
            'charset' => $request->payloadString('charset') ?: 'utf8mb4',
        ], $this->ctx->actor($request));

        // รหัสผ่านที่สุ่มให้ต้องอยู่ในกล่องที่ผู้ใช้กดปิดเอง — ไม่มีที่อื่นให้ดูย้อนหลัง
        return $this->done(
            sprintf('สร้างฐานข้อมูล %s แล้ว', (string) $result['name']),
            [
                [
                    'type' => 'modal',
                    'action' => 'show',
                    'title' => 'สร้างฐานข้อมูลแล้ว',
                    'content' => sprintf(
                        '<p>ฐานข้อมูล <strong>%s</strong> · ผู้ใช้ <strong>%s</strong></p>'
                        . '<p>รหัสผ่าน — คัดลอกไว้ก่อนปิดหน้าต่างนี้ เพราะระบบไม่เก็บไว้ที่ใดอีก</p>'
                        . '<p class="mono selectable">%s</p>',
                        htmlspecialchars((string) $result['name'], ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string) ($result['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars((string) ($result['password'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    ),
                ],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'databases'],
            ],
            $result,
            201,
        )->withHeader('Location', '/api/v2/databases/' . rawurlencode((string) $result['name']));
    }

    /**
     * ลบฐานข้อมูล — ต้องยืนยันด้วยชื่อ และเลือกได้ว่าจะลบผู้ใช้ที่ผูกอยู่ด้วยหรือไม่
     *
     * `drop_user` ไม่ใช่ค่าปริยาย เพราะผู้ใช้ฐานข้อมูลหนึ่งคนอาจมีสิทธิ์กับหลายฐานข้อมูล
     * การลบทิ้งอัตโนมัติจะทำให้เว็บอื่นที่ใช้ผู้ใช้เดียวกันต่อฐานข้อมูลไม่ได้ทันที
     */
    public function destroy(Request $request): Response
    {
        $name = rawurldecode($request->param('name'));
        $confirm = trim($request->payloadString('confirm')) ?: trim($request->get('confirm'));
        $dropUser = $request->payload('drop_user', $request->get('drop_user'));

        $this->agent()->data('db.drop', [
            'name' => $name,
            'confirm' => $confirm,
            'drop_user' => in_array($dropUser, [true, '1', 1, 'true'], true) ? '1' : '0',
        ], $this->ctx->actor($request));

        return $this->completed(sprintf('ลบฐานข้อมูล %s แล้ว', $name), 'databases', ['name' => $name]);
    }

    /**
     * ตั้งรหัสผ่านใหม่ให้ผู้ใช้ฐานข้อมูล
     *
     * เป็น sub-resource ของ `database-users` ไม่ใช่ของ `databases` เพราะผู้ใช้หนึ่งคน
     * ใช้ได้กับหลายฐานข้อมูล — การวางไว้ใต้ฐานข้อมูลใดฐานข้อมูลหนึ่งจะสื่อความหมายผิด
     */
    public function resetPassword(Request $request): Response
    {
        $result = $this->agent()->data('db.user_password', [
            'username' => rawurldecode($request->param('user')),
            'host' => $request->payloadString('host') ?: 'localhost',
        ], $this->ctx->actor($request));

        return $this->done(
            sprintf('ตั้งรหัสผ่านใหม่ให้ %s แล้ว', (string) ($result['username'] ?? '')),
            [[
                'type' => 'modal',
                'action' => 'show',
                'title' => 'รหัสผ่านใหม่',
                'content' => sprintf(
                    '<p>รหัสผ่านใหม่ของผู้ใช้ฐานข้อมูล <strong>%s</strong> — คัดลอกไว้ก่อนปิดหน้าต่างนี้</p>'
                    . '<p class="mono selectable">%s</p>',
                    htmlspecialchars((string) ($result['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) ($result['password'] ?? ''), ENT_QUOTES, 'UTF-8'),
                ),
            ]],
            $result,
        );
    }

    /**
     * กรองตามชื่อฐานข้อมูล ชื่อเว็บไซต์ หรือชื่อผู้ใช้
     *
     * @param list<array<string,mixed>> $databases
     * @return list<array<string,mixed>>
     */
    private static function filter(array $databases, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter($databases, static function (array $db) use ($needle): bool {
            if (str_contains(mb_strtolower((string) ($db['name'] ?? '')), $needle)) {
                return true;
            }

            if (str_contains(mb_strtolower((string) ($db['site'] ?? '')), $needle)) {
                return true;
            }

            foreach ($db['users'] ?? [] as $user) {
                if (str_contains(mb_strtolower((string) ($user['username'] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
