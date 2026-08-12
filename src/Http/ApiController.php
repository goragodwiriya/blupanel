<?php

declare (strict_types = 1);

namespace Phpcp\Http;

use Phpcp\Agent\AgentException;
use Phpcp\Controller\Controller;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ฐานของ controller ทุกตัวใน REST API v2 — PLAN-V2 §4
 *
 * กฎเดียวที่ทุกตัวต้องทำตาม: **ไม่มี HTML ออกจากที่นี่แม้แต่ไบต์เดียว** ไม่ว่าจะสำเร็จ
 * ล้มเหลว หรือระเบิดกลางทาง · ไม่มี redirect · ไม่มีการส่งผลลัพธ์ผ่าน query string
 *
 * สืบทอดจาก Controller เดิมเพราะ HttpKernel สร้าง controller ด้วยลายเซ็นเดียวกันหมด
 * แต่ห้ามเรียก view()/redirect() ที่ได้มา — สองเมธอดนั้นเป็นของ UI แบบ HTML ที่จะถูก
 * ปลดระวางในเฟส D
 */
abstract class ApiController extends Controller
{
    /** ค่าเริ่มต้นและเพดานของการแบ่งหน้า — §4.5 */
    protected const PER_PAGE_DEFAULT = 50;
    protected const PER_PAGE_MAX = 200;

    /**
     * ชื่อเหตุการณ์ที่หน้ารายละเอียดใช้สั่งให้ตัวเองโหลดข้อมูลใหม่
     *
     * มีชื่อเดียวทั้งระบบ เพื่อให้เทมเพลตทุกหน้าเขียน `data-refresh-event` ค่าเดียวกัน
     * และ controller ไม่ต้องรู้ว่าหน้าไหนตั้งชื่ออะไรไว้
     */
    protected const RELOAD_EVENT = 'phpcp:reload';

    /**
     * สำเร็จ พร้อมข้อมูล
     *
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>             $meta
     * @param array<string,mixed>            $actions
     * @param array<string,mixed>             $filters
     * @param array<string,mixed>             $options
     */
    protected function ok(array $data = [], array $meta = [], array $actions = [], array $filters = [], array $options = []): Response
    {
        $body = ['ok' => true, 'data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        // สั่งงานหน้าจอจากฝั่งเซิร์ฟเวอร์ — `ResponseHandler` ของ Now.js อ่านคีย์นี้เอง
        //
        // ใช้กับผลลัพธ์ที่ **ต้องให้ผู้ใช้เห็นเนื้อหาในคำตอบ** ไม่ใช่แค่รู้ว่าสำเร็จ
        // เช่นรหัสผ่านที่ระบบสุ่มให้ ซึ่งไม่มีที่อื่นให้ดูย้อนหลังอีกเลย · หน้าจอ
        // จึงไม่ต้องมีโค้ดเฉพาะกิจสำหรับ endpoint ไหนเลย
        //
        // ชนิดที่ใช้ได้: notification · alert · modal · redirect · update · render ·
        // remove · class · attribute · focus · scroll · clipboard · download · event
        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        // ตัวกรองที่ผู้เรียกขอ — `filters` ตามสัญญา §4.5
        // ['status' => [['value'=> '1', 'text' => 'Active'], ['value'=> '0', 'text' => 'Inactive']],
        // 'department' => [['value'=> '1', 'text' => 'Department 1'], ['value'=> '2', 'text' => 'Department 2']]]
        if ($filters !== []) {
            $body['filters'] = $filters;
        }

        // ตัวเลือกที่ผู้เรียกขอ — `options` ตามสัญญา §4.5
        // ['status' => [['value'=> '1', 'text' => 'Active'], ['value'=> '0', 'text' => 'Inactive']],
        // 'department' => [['value'=> '1', 'text' => 'Department 1'], ['value'=> '2', 'text' => 'Department 2']]]
        if ($filters !== []) {
            $body['filters'] = $filters;
        }
        if ($options !== []) {
            $body['options'] = $options;
        }

        return Response::json($body);
    }

    /**
     * คำตอบของคำสั่ง — **ห้ามมีคีย์ `data`**
     *
     * Now.js แกะคำตอบด้วย `response.data.data ?? response.data` · กฎที่ทำให้มันใช้ได้คือ
     * **คำตอบหนึ่งทำหน้าที่เดียว**: คำตอบสำหรับ*อ่าน*มี `data` ให้ผูกกับหน้าจอ ส่วนคำตอบ
     * สำหรับ*สั่งงาน*มี `actions` ให้เฟรมเวิร์กทำตาม · ถ้าส่งทั้งสองอย่างมาพร้อมกัน
     * มันจะเลือกชั้นในแล้วมองไม่เห็น `actions` เลย ผลลัพธ์ที่ผู้ใช้ต้องอ่านก็หายไปเงียบ ๆ
     *
     * ค่าที่ผู้เรียกต้องใช้จริง (รหัสผ่านที่สุ่มให้ · เส้นทางไฟล์ที่ส่งออก) ใส่ผ่าน
     * `$extra` ซึ่งไปอยู่ **ระดับบนสุดของคำตอบ** ไม่ใช่ในชั้น `data`
     *
     * @param array<string,mixed> $extra
     * @param list<array<string,mixed>> $actions
     */
    protected function done(string $message, array $actions = [], array $extra = [], int $status = 200): Response
    {
        $message = $this->t($message);
        $actions = $this->translateActions($actions);

        $body = ['ok' => true, 'message' => $message] + $extra;

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        return Response::json($body, $status);
    }

    /**
     * แจ้งผลสำเร็จพร้อมโหลดตารางใหม่ — รูปที่ใช้บ่อยที่สุดของ `done()`
     *
     * @param string $table ชื่อ `data-table` ที่ต้องโหลดใหม่ · ว่าง = ไม่โหลดอะไร
     * @param array<string,mixed> $extra
     */
    protected function completed(string $message, string $table = '', array $extra = []): Response
    {
        $actions = [['type' => 'notification', 'level' => 'success', 'message' => $message]];

        if ($table !== '') {
            $actions[] = ['type' => 'redirect', 'url' => 'reload', 'target' => $table];
        }

        return $this->done($message, $actions, $extra);
    }

    /**
     * แจ้งผลสำเร็จพร้อมสั่งให้หน้ารายละเอียดโหลดตัวเองใหม่
     *
     * ใช้แทน `completed()` เมื่อสิ่งที่ต้องอัปเดตไม่ใช่ตารางที่ดึงข้อมูลเอง แต่เป็น
     * `data-component="api"` ที่ครอบทั้งหน้า (เช่นหน้าเว็บไซต์เดียว) — ตารางลูกของมัน
     * รับข้อมูลผ่าน `data-attr="data:domains"` จึงสั่ง reload ตรง ๆ ไม่ได้
     *
     * ชื่อเหตุการณ์ต้องตรงกับ `data-refresh-event` ของหน้านั้น
     *
     * @param array<string,mixed> $extra
     */
    protected function refreshed(string $message, string $target = '', array $extra = []): Response
    {
        return $this->done($message, [
            ['type' => 'notification', 'level' => 'success', 'message' => $message],
            ['type' => 'redirect', 'url' => 'reload', 'target' => $target]
        ], $extra);
    }

    /**
     * สร้างสำเร็จ — ต้องแนบ Location ของทรัพยากรที่เพิ่งเกิดขึ้นเสมอ
     *
     * @param array<string,mixed> $data
     */
    protected function created(array $data, string $location, array $actions = []): Response
    {
        $body = ['ok' => true, 'data' => $data];

        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        return Response::json($body, 201)
            ->withHeader('Location', $location);
    }

    /**
     * รับงานเข้าคิวแล้ว ใช้กับงานยาวที่ผลลัพธ์ยังไม่พร้อม (เช่นสำรองข้อมูล)
     *
     * @param array<string,mixed> $data
     */
    protected function accepted(array $data = []): Response
    {
        return Response::json(['ok' => true, 'data' => $data], 202);
    }

    /**
     * สำเร็จ ไม่มีอะไรต้องตอบกลับ
     *
     * body ต้องว่างจริง ๆ ตามมาตรฐาน HTTP (204 ห้ามมีเนื้อหา) แต่ยังตั้ง Content-Type
     * เป็น JSON เพื่อให้คำตอบทุกชนิดของ v2 มี Content-Type เดียวกันหมด — ฝั่ง SPA
     * จึงตรวจชนิดคำตอบได้ที่เดียวโดยไม่ต้องแยกกรณี 204 ออกมาเป็นพิเศษ
     */
    protected function noContent(): Response
    {
        return Response::noContent()->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * สิทธิ์ที่ผู้เรียกมีกับทรัพยากรที่กำลังตอบอยู่
     *
     * ใส่ไปกับทรัพยากรเดี่ยวในคีย์ `can` เพื่อให้หน้าจอเขียน `data-if="can['delete']"`
     * ได้ตรง ๆ · **เป็นคำตอบจากเซิร์ฟเวอร์ ไม่ใช่การคำนวณซ้ำฝั่งหน้าจอ** — หน้าจอ
     * ไม่ต้องรู้ว่าบทบาทไหนทำอะไรได้ ซึ่งเป็นความรู้ที่ซ้ำแล้วเพี้ยนได้ง่ายที่สุด
     *
     * ค่าที่ได้เป็น map เสมอ (ทุกคีย์มีค่า true/false) ไม่ใช่รายการเฉพาะที่อนุญาต —
     * `data-if` ของ Now.js เปิดเผยเมื่อเจอค่า undefined การส่งเฉพาะที่อนุญาตจึงทำให้
     * ปุ่มที่ควรถูกซ่อนกลับโผล่ · เหตุผลเดียวกับ permission map ของ /api/v2/session
     *
     * @param array<string,string> $map ชื่อที่หน้าจอใช้ => permission ที่ต้องมี
     * @return array<string,bool>
     */
    protected function can(array $map): array
    {
        return array_map(fn(string $permission): bool => $this->ctx->can($permission), $map);
    }

    /**
     * ตอบแบบแบ่งหน้า — meta ต้องมีครบทั้งสี่ค่าเสมอเพื่อให้ฝั่ง SPA คำนวณตัวแบ่งหน้าได้
     *
     * @param list<mixed> $items
     */
    protected function paginate(array $items, int $total, int $page, int $perPage): Response
    {
        return $this->ok($items, [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0
        ]);
    }

    /** @param array<string,string> $fields */
    protected function problem(ApiProblem $problem, string $message, array $fields = []): Response
    {
        return $problem->response(
            $this->t($message),
            array_map(fn (string $text): string => $this->t($text), $fields),
        );
    }

    /**
     * แปลข้อความที่จะส่งออกไปให้คนอ่าน
     *
     * **โค้ดเขียนภาษาอังกฤษเสมอ** — ข้อความอังกฤษคือคีย์ของคลังคำไปในตัว ตามกติกา
     * เดียวกับที่หน้าเว็บใช้ (`public/assets/spa/lang/th.json` คือไฟล์เดียวกัน)
     * คีย์ที่ยังไม่มีคำแปลจะออกไปเป็นอังกฤษ ซึ่งอ่านรู้เรื่อง ไม่ใช่รหัสที่ไม่มีความหมาย
     *
     * @param array<string,string|int|float> $params ค่าที่แทนที่ `{ชื่อ}` ในข้อความ
     */
    protected function t(string $key, array $params = []): string
    {
        return $this->app->t($key, $params);
    }

    /**
     * แปลข้อความในคำสั่งที่ส่งไปให้หน้าจอทำงานต่อ
     *
     * `notification` กับ `alert` มีข้อความที่ผู้ใช้อ่าน ส่วนชนิดอื่น (redirect, event,
     * modal) ไม่มี · หัวข้อของ modal ใช้รูป `{LNG_...}` ซึ่งหน้าเว็บแปลเองอยู่แล้ว
     *
     * @param array<int,array<string,mixed>> $actions
     * @return array<int,array<string,mixed>>
     */
    private function translateActions(array $actions): array
    {
        foreach ($actions as $index => $action) {
            if (is_array($action) && isset($action['message']) && is_string($action['message'])) {
                $actions[$index]['message'] = $this->t($action['message']);
            }
        }

        return $actions;
    }

    /**
     * เวอร์ชัน PHP ที่ติดตั้งจริงจาก agent เรียง "ใช้งานได้ก่อน แล้วค่อยใหม่สุด"
     *
     * ตัวแรกหลังเรียงคือค่าที่ควรใช้เป็นค่าเริ่มต้นของ `<select>` เสมอ — ทั้ง
     * PhpVersionsController (หน้ารายการ) และ SitesController (ฟอร์มสร้าง/แก้ไขเว็บไซต์)
     * เรียกใช้ร่วมกัน เพื่อไม่ให้เว็บใหม่ได้ default ที่ FPM ไม่ทำงานจริงจากการเรียง
     * คนละแบบระหว่างสองหน้า
     *
     * ไม่เช็ค `isAvailable()` ให้ — ผู้เรียกตัดสินใจเองว่าจะยอมให้ agent ล่มแล้วได้
     * รายการว่างกลับมา หรือปล่อยให้ AgentException ลอยขึ้นไป
     *
     * @return array<string,mixed> โครงเดิมจาก agent แต่ `versions` เรียงแล้วเป็น list
     */
    protected function fetchPhpVersions(Request $request): array
    {
        $data = $this->agent()->data('php.list', [], $this->ctx->actor($request));

        // agent คืนเป็น map ที่คีย์เป็นเลขเวอร์ชัน — REST ต้องเป็น array เรียงลำดับ
        // ไม่งั้น JSON จะกลายเป็น object ที่ฝั่ง client วนซ้ำแล้วได้ลำดับไม่แน่นอน
        $versions = array_values($data['versions'] ?? []);

        usort($versions, static function (array $a, array $b): int {
            $runnable = (int) (bool) ($b['fpm_running'] ?? false) <=> (int) (bool) ($a['fpm_running'] ?? false);

            return $runnable !== 0
                ? $runnable
                : version_compare((string) $b['version'], (string) $a['version']);
        });

        $data['versions'] = $versions;

        return $data;
    }

    /**
     * แปลงข้อผิดพลาดจาก agent เป็นคำตอบตามสัญญา
     *
     * ใช้เมื่อ controller ต้องทำอย่างอื่นต่อหลังจาก agent ล้ม (เช่นย้อนสิ่งที่ทำไปแล้ว)
     * กรณีทั่วไปไม่ต้องเรียกเอง — ปล่อยให้ exception ลอยขึ้นไปให้ HttpKernel จัดการ
     * ซึ่งได้ผลเหมือนกันเป๊ะและได้บันทึก log ด้วย
     */
    protected function failFromAgent(AgentException $e): Response
    {
        return ApiProblem::fromAgentException($e)->response($e->getMessage());
    }

    /**
     * พารามิเตอร์การแบ่งหน้าที่ถูกบีบเข้าช่วงแล้ว — §4.5
     *
     * @return array{page:int,per_page:int,offset:int}
     */
    protected function pagination(Request $request): array
    {
        $page = max(1, $request->queryInt('page', 1));
        // `per_page` คือชื่อในสัญญา §4.5 · `pageSize` คือชื่อที่ตารางของ Now.js ส่งมา
        // รับทั้งสองชื่อที่นี่ที่เดียว ทุก endpoint รายการจึงพูดภาษาเดียวกับหน้าจอได้
        // โดยไม่ต้องมี JS แปลงพารามิเตอร์ และไม่ต้องแก้เฟรมเวิร์ก
        $perPage = $request->get('per_page') !== ''
            ? $request->queryInt('per_page', self::PER_PAGE_DEFAULT)
            : $request->queryInt('pageSize', self::PER_PAGE_DEFAULT);
        $perPage = max(1, min($perPage, self::PER_PAGE_MAX));

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }

    /**
     * คำค้นที่ผู้เรียกขอ — `q` ตามสัญญา หรือ `search` ตามที่ตารางของ Now.js ส่งมา
     */
    protected function searchTerm(Request $request): string
    {
        $term = trim($request->get('q'));

        return $term !== '' ? $term : trim($request->get('search'));
    }

    /**
     * การเรียงลำดับที่ผู้เรียกขอ
     *
     * รับสองรูปแบบ: `-field` ตามสัญญา §4.5 และ `field desc` ที่ตารางของ Now.js ส่งมา
     * (ตารางส่งได้หลายคู่คั่นด้วยจุลภาค — ใช้คู่แรก เพราะทุก endpoint เรียงได้ชั้นเดียว)
     *
     * ชื่อฟิลด์ถูกจำกัดด้วย allowlist ของผู้เรียกเสมอ เพราะค่านี้ไปต่อท้าย ORDER BY
     * ห้ามส่งค่าดิบจากผู้ใช้เข้า SQL ไม่ว่ากรณีใด
     *
     * @param list<string> $allowed
     * @return array{field:string,desc:bool}
     */
    protected function sort(Request $request, array $allowed, string $default): array
    {
        $raw = trim(explode(',', $request->get('sort'))[0]);

        $desc = str_starts_with($raw, '-');
        $field = ltrim($raw, '-');

        if (str_contains($field, ' ')) {
            [$field, $direction] = explode(' ', $field, 2);
            $desc = strtolower(trim($direction)) === 'desc';
        }

        return [
            'field' => in_array($field, $allowed, true) ? $field : $default,
            'desc' => $desc
        ];
    }
}
