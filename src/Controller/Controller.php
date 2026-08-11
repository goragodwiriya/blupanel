<?php

declare (strict_types = 1);

namespace Phpcp\Controller;

use Phpcp\Agent\Client;
use Phpcp\Kernel\App;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Response;
use Phpcp\Kernel\Router;

/**
 * ฐานของ controller ทุกตัว — รวมสิ่งที่ทุกเส้นทางต้องใช้ไว้ที่เดียว
 *
 * เหลือผู้สืบทอดสองตัวหลัง UI แบบ HTML ถูกลบ: `ApiController` (ทุกเส้นทาง `/api/v2/*`)
 * กับ `SpaController` (shell ไฟล์เดียว) · เมท็อดที่เคยมีไว้ให้หน้า HTML — `view()`,
 * `safeNext()` — ถูกลบไปพร้อมกัน ไม่มีใครเรียกอีกแล้ว
 */
abstract class Controller
{
    /**
     * @param App $app
     * @param Ctx $ctx
     * @param Router $router
     */
    public function __construct(
        protected readonly App $app,
        protected readonly Ctx $ctx,
        protected readonly Router $router,
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * @param array $data
     */
    protected function ok(array $data = []): Response
    {
        return Response::json(['ok' => true] + $data);
    }

    /**
     * @param string $message
     * @param int $status
     */
    protected function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => $message], $status);
    }

    /**
     * @param string $to
     */
    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /**
     * @return mixed
     */
    protected function agent(): Client
    {
        return $this->app->agent();
    }

    /**
     * @param array $datas
     * @return mixed
     */
    protected function toOptions(array $datas): array
    {
        $options = [];
        foreach ($datas as $data) {
            $options[] = ['value' => $data->id, 'text' => (string) $data];
        }
        return $options;
    }
}
