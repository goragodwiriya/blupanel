<?php

declare (strict_types = 1);

namespace Phpcp\Http\V2;

use Phpcp\Agent\Actor;
use Phpcp\Domain\FileCatalog;
use Phpcp\Domain\FileRoots;
use Phpcp\Domain\FileScope;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ตัวจัดการไฟล์ — `/api/v2/files` (PLAN-V2 §4.6, ทรัพยากรที่ซับซ้อนที่สุดของเฟส B)
 *
 * **ทุก endpoint ที่นี่ไม่แตะไฟล์เองแม้แต่ไบต์เดียว** — ส่งต่อให้ capability ทั้งหมด
 * ซึ่งเป็นที่ที่ `PathGuard` ตรวจสองชั้น (lexical แล้วตามด้วย realpath หลังลดสิทธิ์)
 * และเป็นที่ที่งานไฟล์ถูกทำในสิทธิ์ของเจ้าของเว็บผ่าน `Executor::asUser()`
 * ตาม ARCHITECTURE §4.4 · ชั้นนี้มีหน้าที่แค่ตรวจว่าผู้เรียกเปิดขอบเขตนี้ได้ไหม
 * แล้วแปลงคำขอ HTTP เป็น argument
 *
 * ทุกคำสั่งอ้าง `root` (คีย์ของขอบเขต) + `path` (เส้นทางสัมพัทธ์ในขอบเขตนั้น) เสมอ
 * ไม่มี endpoint ไหนรับเส้นทางสัมบูรณ์ของเครื่อง — นั่นคือสิ่งที่ทำให้ traversal
 * ออกนอกขอบเขตเป็นไปไม่ได้ตั้งแต่รูปแบบของ API ไม่ใช่แค่จากการตรวจค่า
 */
final class FilesController extends ApiController
{
    /**
     * ขอบเขตไฟล์ที่ผู้เรียกเปิดได้
     *
     * SPA ต้องเรียกอันนี้ก่อนเสมอเพื่อรู้ว่ามีอะไรให้เปิดบ้าง — endpoint นี้ไม่ได้อยู่ใน
     * ตารางแปลงเส้นทางของ §4.6 เพราะ UI เดิมได้ข้อมูลนี้มาพร้อมหน้า HTML
     * แต่ SPA ไม่มีหน้า HTML ให้แนบมาด้วย จึงต้องมีทางขอแยก
     */
    public function roots(Request $request): Response
    {
        $scopes = [];

        foreach ($this->scopes() as $key => $scope) {
            $scopes[] = [
                'key' => $key,
                'label' => $scope->label,
                'kind' => $scope->kind,
                'site_id' => $scope->siteId,
                'writable' => $scope->writable
                // ไม่ส่ง `root` (เส้นทางจริงบนเครื่อง) ให้ฝั่งหน้าเว็บ — มันไม่มีอะไรให้ทำกับค่านั้น
                // และการเปิดเผยโครงสร้างไดเรกทอรีของเครื่องโดยไม่จำเป็นคือการช่วยผู้โจมตีฟรี ๆ
            ];
        }

        return $this->ok($scopes, [
            'max_upload_bytes' => FileCatalog::MAX_TRANSFER_BYTES,
            'max_edit_bytes' => FileCatalog::MAX_EDIT_BYTES
        ]);
    }

    /** รายการไฟล์ในโฟลเดอร์ */
    public function index(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.list', [
            'root' => $root,
            'path' => $request->get('path'),
            'page' => $request->get('page'),
            'per_page' => $request->get('per_page')
        ], $this->ctx->actor($request));

        // capability แบ่งหน้ามาให้แล้ว — ยกค่าที่มันคำนวณไว้ขึ้นเป็น meta ตามรูปแบบ §4.2
        $entries = $data['entries'] ?? [];
        unset($data['entries']);

        return $this->ok($entries, $data);
    }

    /**
     * โครงโฟลเดอร์สำหรับแถบนำทางด้านซ้าย
     *
     * ใช้ `resolveRoot` เหมือน `index` เพราะแถบนำทางถูกวาดพร้อมหน้าจอ ก่อนที่คำตอบของ
     * `/files/roots` (คนละคำขอ) จะมาถึง — คำขอแรกจึงยังไม่มี `root` ติดมาด้วยเสมอ
     */
    public function tree(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.tree', [
            'root' => $root,
            'path' => $request->get('path'),
            'depth' => $request->get('depth')
        ], $this->ctx->actor($request));

        $nodes = $data['nodes'] ?? [];
        unset($data['nodes']);

        return $this->ok($nodes, $data);
    }

    /** ค้นหาไฟล์ตามชื่อใต้โฟลเดอร์ที่เปิดอยู่ */
    public function search(Request $request): Response
    {
        $root = $this->resolveRoot($request->get('root'));

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $data = $this->agent()->data('file.search', [
            'root' => $root,
            'path' => $request->get('path'),
            // `q` คือชื่อในสัญญา §4.5 · `searchTerm` รับชื่อที่ตารางของ Now.js ส่งมาด้วย
            'q' => $this->searchTerm($request)
        ], $this->ctx->actor($request));

        $entries = $data['entries'] ?? [];
        unset($data['entries']);

        return $this->ok($entries, $data);
    }

    /** ข้อมูลของไฟล์หรือโฟลเดอร์เดียว — กล่องคุณสมบัติ */
    public function info(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        return $this->ok($this->agent()->data('file.info', [
            'root' => $root,
            'path' => $request->get('path')
        ], $this->ctx->actor($request)));
    }

    /** เนื้อหาไฟล์ข้อความสำหรับแก้ไข */
    public function read(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        return $this->ok($this->agent()->data('file.read', [
            'root' => $root,
            'path' => $request->get('path')
        ], $this->ctx->actor($request)));
    }

    /**
     * บันทึกเนื้อหาไฟล์
     *
     * `create` แยกจากการเขียนทับโดยเจตนา — ค่าปริยายคือ "เขียนทับไฟล์ที่มีอยู่เท่านั้น"
     * การพิมพ์ชื่อไฟล์ผิดจึงได้ error ไม่ใช่สร้างไฟล์เปล่าไว้ในที่ที่ไม่ตั้งใจ
     */
    public function write(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.write', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'content' => (string) $request->payload('content', ''),
            'create' => $this->flag($request, 'create')
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($result['message'] ?? 'File saved'),
            extra: is_array($result) ? $result : [],
        );
    }

    /**
     * ดาวน์โหลดไฟล์
     *
     * **ข้อยกเว้นเดียวของกฎ "ทุก endpoint ตอบ JSON"** — ไฟล์ไบนารีที่ห่อด้วย base64
     * ใน JSON จะโตขึ้น 33% และบังคับให้ฝั่ง client ถอดรหัสทั้งไฟล์ในหน่วยความจำก่อน
     * บันทึกได้ ซึ่งใช้ไม่ได้จริงกับไฟล์ขนาดหลายสิบเมกะไบต์ · ข้อยกเว้นนี้ระบุไว้ใน
     * `docs/openapi.yaml` อย่างชัดเจนแล้ว
     *
     * ส่งเป็น octet-stream + nosniff เสมอ — ถ้าปล่อยให้เบราว์เซอร์เดาชนิดเอง
     * ไฟล์ .html ของผู้ใช้จะถูกเรนเดอร์ในโดเมนของ panel แล้วกลายเป็น stored XSS
     * ที่ขโมย session ของผู้ดูแลได้ทันที
     *
     * **ไม่มีเพดานขนาดแล้ว** — เดิมปฏิเสธไฟล์ที่ใหญ่กว่า 2.5 MB (ขนาดเฟรมของโปรโตคอล)
     * พร้อมบอกให้ไปบีบอัดเป็น zip ก่อน ซึ่งเป็นคำแนะนำที่ใช้ไม่ได้กับไฟล์สำรองที่บีบ
     * มาแล้วและใหญ่กว่านั้นเสมอ · ตอนนี้ขอจาก agent ทีละก้อนแล้วสตรีมต่อออกไปเลย
     */
    public function download(Request $request): Response
    {
        $root = $request->get('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $path = $request->get('path');
        $actor = $this->ctx->actor($request);

        // ก้อนแรกทำสองหน้าที่: บอกขนาดทั้งไฟล์ และตรวจสิทธิ์/ความมีอยู่จริงก่อนที่
        // เราจะส่ง header ออกไป · ล้มตรงนี้ยังตอบเป็น JSON ที่มีรหัสข้อผิดพลาดได้อยู่
        $first = $this->agent()->data('file.download', [
            'root' => $root,
            'path' => $path,
            'offset' => 0,
        ], $actor);

        if (base64_decode((string) ($first['content'] ?? ''), true) === false) {
            return $this->problem(ApiProblem::InternalError, 'The file could not be decoded');
        }

        $size = (int) ($first['size'] ?? 0);
        $agent = $this->agent();

        /*
         * ทยอยขอทีละก้อนจนหมดไฟล์ แล้วส่งออกทันทีทีละก้อน
         *
         * **ห้ามประกอบทั้งไฟล์ก่อนส่ง** — ไฟล์สำรองของเว็บจริงมีขนาดระดับกิกะไบต์
         * การถือไว้ทั้งไฟล์คือการทำให้ PHP ตายด้วย memory_limit ในงานที่ผู้ใช้ต้องการ
         * มันที่สุด · ก้อนละ 2.5 MB ตามเพดานของเฟรมโปรโตคอล (ดู FileDownload)
         *
         * `Content-Length` ส่งได้เพราะรู้ขนาดตั้งแต่ก้อนแรก — เบราว์เซอร์จึงขึ้นแถบ
         * ความคืบหน้าจริง ไม่ใช่ตัวเลขที่ไต่ขึ้นไปเรื่อย ๆ โดยไม่รู้ปลายทาง
         */
        $stream = static function (callable $emit) use ($first, $agent, $root, $path, $actor): void {
            $chunk = $first;

            while (true) {
                $emit((string) base64_decode((string) ($chunk['content'] ?? ''), true));

                if (($chunk['eof'] ?? true) === true || (int) ($chunk['bytes'] ?? 0) <= 0) {
                    return;
                }

                $chunk = $agent->data('file.download', [
                    'root' => $root,
                    'path' => $path,
                    'offset' => (int) $chunk['offset'] + (int) $chunk['bytes'],
                ], $actor);
            }
        };

        return Response::stream($stream, FileCatalog::downloadType())
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Content-Disposition', self::disposition((string) ($first['name'] ?? 'download')))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * อัปโหลดไฟล์
     *
     * รับได้สองแบบ: multipart (`files[]`) จากเบราว์เซอร์ และ JSON ที่มี `name` +
     * `content_base64` สำหรับสคริปต์และ curl · แบบที่สองจำเป็นเพราะเกณฑ์รับงานเฟส B
     * คือ "เรียกได้ครบทุกทรัพยากรด้วย curl โดยไม่ต้องเปิดเบราว์เซอร์เลย"
     *
     * ส่งให้ agent ทีละไฟล์เพราะโปรโตคอลมีเพดานขนาดต่อหนึ่งคำสั่ง — การรวมหลายไฟล์
     * เป็นคำสั่งเดียวจะทำให้อัปโหลดไฟล์เล็ก ๆ หลายไฟล์ล้มทั้งชุด
     */
    public function upload(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $path = $request->payloadString('path');
        $overwrite = $this->flag($request, 'overwrite');
        $uploaded = [];

        foreach ($request->files('files') as $file) {
            $error = self::uploadError($file);

            if ($error !== null) {
                return $this->problem(ApiProblem::ValidationError, $error, ['files' => $error]);
            }

            // is_uploaded_file กันไม่ให้ tmp_name ที่ถูกปลอมชี้ไปยังไฟล์อื่นบนเครื่อง
            if (!is_uploaded_file($file['tmp_name'])) {
                return $this->problem(ApiProblem::ValidationError, 'The uploaded file is not valid');
            }

            $content = file_get_contents($file['tmp_name']);

            if ($content === false) {
                return $this->problem(ApiProblem::ValidationError, 'The uploaded file could not be read');
            }

            $uploaded[] = $this->sendFile($request, $root, $path, (string) $file['name'], base64_encode($content), $overwrite);
        }

        // แบบ JSON — ไฟล์เดียวต่อคำขอ
        $name = trim($request->payloadString('name'));
        if ($uploaded === [] && $name !== '') {
            $uploaded[] = $this->sendFile(
                $request,
                $root,
                $path,
                $name,
                (string) $request->payload('content_base64', ''),
                $overwrite,
            );
        }

        if ($uploaded === []) {
            return $this->problem(
                ApiProblem::ValidationError,
                'No uploaded file was received',
                ['files' => 'ส่งไฟล์มาทาง multipart `files[]` หรือส่ง `name` + `content_base64` มาทาง JSON'],
            );
        }

        return $this->completed(
            $this->t('{count} files uploaded', ['count' => count($uploaded)]),
            'files',
            ['uploaded' => $uploaded, 'count' => count($uploaded)],
        );
    }

    /** สร้างโฟลเดอร์ */
    public function makeDirectory(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.mkdir', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'name' => $request->payloadString('name')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Folder created'), 'files', $result);
    }

    /** ย้ายหรือเปลี่ยนชื่อ */
    public function move(Request $request): Response
    {
        return $this->transfer($request, copy: false);
    }

    /**
     * คัดลอก
     *
     * ใช้ capability เดียวกับการย้ายโดยตั้ง `copy` — แยกเป็นคนละ endpoint เพราะ
     * "คัดลอก" กับ "ย้าย" เป็นคนละการกระทำในสายตาผู้ใช้ และการซ่อนความต่างไว้ในแฟล็ก
     * ของ body ทำให้อ่าน log ย้อนหลังแล้วแยกไม่ออกว่าเกิดอะไรขึ้น
     */
    public function copy(Request $request): Response
    {
        return $this->transfer($request, copy: true);
    }

    /** ลบไฟล์หรือโฟลเดอร์ที่เลือก */
    public function destroy(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $items = $this->items($request);

        $this->agent()->data('file.delete', [
            'root' => $root,
            'items' => $items
        ], $this->ctx->actor($request));

        return $this->completed(
            $this->t('{count} items deleted', ['count' => count($items)]),
            'files',
            ['root' => $root, 'removed' => count($items)],
        );
    }

    /** เปลี่ยนสิทธิ์ไฟล์ */
    public function setPermissions(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.chmod', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'mode' => $request->payloadString('mode'),
            'recursive' => $this->flag($request, 'recursive')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Permissions changed'), 'files', is_array($result) ? $result : []);
    }

    /** สร้างไฟล์บีบอัดจากรายการที่เลือก */
    public function archive(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.zip', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'items' => $this->items($request),
            'archive' => $request->payloadString('archive')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Files compressed'), 'files', $result);
    }

    /** แตกไฟล์บีบอัด */
    public function extract(Request $request): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.unzip', [
            'root' => $root,
            'path' => $request->payloadString('path'),
            'destination' => $request->payloadString('destination')
        ], $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Archive extracted'), 'files', is_array($result) ? $result : []);
    }

    /** ย้าย/คัดลอก — ต่างกันแค่แฟล็กเดียวที่ส่งให้ capability */
    private function transfer(Request $request, bool $copy): Response
    {
        $root = $request->payloadString('root');

        if (!$this->mayAccess($root)) {
            return $this->deniedScope();
        }

        $result = $this->agent()->data('file.move', [
            'root' => $root,
            'items' => $this->items($request),
            'destination' => $request->payloadString('destination'),
            'rename' => $request->payloadString('rename'),
            'copy' => $copy ? '1' : '',
            'overwrite' => $this->flag($request, 'overwrite')
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? ($copy ? 'Copied' : 'Moved')),
            'files',
            is_array($result) ? $result : [],
        );
    }

    /** ส่งไฟล์หนึ่งไฟล์ให้ agent — คืนชื่อที่บันทึกจริง */
    private function sendFile(
        Request $request,
        string $root,
        string $path,
        string $name,
        string $base64,
        string $overwrite,
    ): string {
        $result = $this->agent()->data('file.upload', [
            'root' => $root,
            'path' => $path,
            'name' => $name,
            'content' => $base64,
            'overwrite' => $overwrite
        ], $this->ctx->actor($request));

        return (string) ($result['name'] ?? $name);
    }

    /**
     * รายการไฟล์ที่ถูกเลือก — รับได้ทั้ง array (JSON) และข้อความคั่นบรรทัด (ฟอร์ม)
     *
     * @return list<string>
     */
    private function items(Request $request): array
    {
        $items = $request->payload('items', []);

        if (is_string($items)) {
            $items = preg_split('/\R/', $items) ?: [];
        }

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $items,
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * แฟล็กแบบ boolean ที่ capability คาดหวังเป็นสตริง
     *
     * capability ชุดไฟล์รับ '' = ไม่ · อะไรก็ตามที่ไม่ว่าง = ใช่ (มาจากฟอร์ม HTML)
     * SPA ส่ง true/false มาเป็น JSON จึงต้องแปลงตรงนี้ ไม่ใช่ไปแก้ที่ capability
     * ซึ่งเป็นชั้นที่แผนกำหนดว่าห้ามแตะ
     */
    private function flag(Request $request, string $key): string
    {
        $value = $request->payload($key, false);

        return in_array($value, [true, 1, '1', 'true', 'on'], true) ? '1' : '';
    }

    /** @return array<string,FileScope> */
    private function scopes(): array
    {
        return FileRoots::forActor(
            new Actor($this->ctx->userId(), $this->ctx->username(), $this->ctx->role()),
            $this->app->db(),
        );
    }

    /**
     * ผู้เรียกเปิดขอบเขตนี้ได้จริงหรือไม่
     *
     * ตรวจที่นี่เพื่อให้ได้ข้อความที่เข้าใจง่าย · agent ตรวจซ้ำอีกชั้นเสมอเพราะต้อง
     * ยืนหยัดได้ลำพังถ้าชั้นเว็บถูกเจาะ (ARCHITECTURE §4.2)
     */
    /**
     * ขอบเขตที่จะใช้จริง — ไม่ระบุมา = ขอบเขตแรกที่ผู้เรียกเปิดได้
     *
     * เหตุผลเดียวกับ `GET /logs` ที่ไม่ระบุ `source`: endpoint รายการที่เรียกเปล่า ๆ
     * ควรได้ค่าเริ่มต้นที่ใช้งานได้ ไม่ใช่ข้อผิดพลาด · หน้าจอของ SPA โหลดตารางก่อนที่
     * ตัวเลือกขอบเขต (ซึ่งมาจาก `/files/roots` อีกคำขอหนึ่ง) จะมาถึง คำขอแรกจึงไม่มี
     * `root` ติดไปด้วยเสมอ
     *
     * ระบุขอบเขตที่ไม่มีสิทธิ์เข้าถึงยังเป็น 403 เหมือนเดิม — "ยังไม่ได้เลือก" กับ
     * "เลือกสิ่งที่แตะไม่ได้" เป็นคนละเรื่องกัน
     */
    private function resolveRoot(string $rootKey): string
    {
        if ($rootKey !== '') {
            return $rootKey;
        }

        $scopes = $this->scopes();

        return $scopes === [] ? '' : (string) array_key_first($scopes);
    }

    /**
     * @param string $rootKey
     * @return mixed
     */
    private function mayAccess(string $rootKey): bool
    {
        return $rootKey !== '' && isset($this->scopes()[$rootKey]);
    }

    /**
     * @return mixed
     */
    private function deniedScope(): Response
    {
        // 403 ไม่ใช่ 404 — คีย์ของขอบเขตไม่ใช่ความลับ (มันคือ site-<id> ที่เดาได้อยู่แล้ว)
        // และการบอกให้ชัดว่า "ไม่มีสิทธิ์" ช่วยให้ผู้ดูแลรู้ว่าต้องไปแก้ที่สิทธิ์ ไม่ใช่ที่ URL
        return $this->problem(ApiProblem::Forbidden, 'You may not access this file area');
    }

    /** @param array{name:string,error:int,size:int} $file */
    private static function uploadError(array $file): ?string
    {
        return match ($file['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้',
            UPLOAD_ERR_PARTIAL => 'อัปโหลดไม่ครบไฟล์',
            UPLOAD_ERR_NO_FILE => 'No uploaded file was received',
            default => 'อัปโหลดไม่สำเร็จ (รหัส '.$file['error'].')',
        };
    }

    /**
     * ส่วนหัว Content-Disposition ที่ปลอดภัยกับชื่อไฟล์ภาษาไทย
     *
     * ชื่อไฟล์ที่ผู้ใช้ตั้งเองอาจมีอัญประกาศหรือขึ้นบรรทัดใหม่ ซึ่งใช้ฉีด header อื่นได้
     * — ส่ง ASCII ที่ถูกล้างแล้วเป็นค่าหลัก และชื่อจริงใน filename* ตาม RFC 5987
     */
    private static function disposition(string $name): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'download';

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($name));
    }
}
