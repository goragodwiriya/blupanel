<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

use Phpcp\Agent\AgentException;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\ErrorPage;
use Phpcp\Middleware\AuditContext;
use Phpcp\Middleware\Authenticate;
use Phpcp\Middleware\Authorize;
use Phpcp\Middleware\CsrfProtection;
use Phpcp\Middleware\Middleware;
use Phpcp\Middleware\RateLimit;
use Phpcp\Middleware\SecurityHeaders;
use Phpcp\Middleware\SessionMiddleware;

/**
 * ประกอบทุกอย่างเข้าด้วยกัน — ARCHITECTURE §3.2
 *
 * ลำดับ middleware สำคัญมากและห้ามสลับ:
 *   SecurityHeaders  ชั้นนอกสุด header ต้องติดไปกับทุกคำตอบ รวมหน้า error
 *   RateLimit        ตัดคำขอที่ยิงรัวก่อนแตะฐานข้อมูลหรือคำนวณ Argon2id
 *   Session          โหลดผู้ใช้ ต้องมาก่อน CSRF เพราะ token ผูกกับ session
 *   Authenticate     ยังไม่ล็อกอิน → ส่งไปหน้าล็อกอิน
 *   CsrfProtection   ตัดคำขอที่ไม่มี token ก่อนแตะ logic ใด ๆ
 *   Authorize        ตรวจ permission ของเส้นทาง
 *   AuditContext     ชั้นในสุด บันทึกสิ่งที่เกิดขึ้นจริง
 */
final class HttpKernel
{
    private readonly Router $router;

    /**
     * @param App $app
     */
    public function __construct(private readonly App $app)
    {
        $this->router = Routes::build();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function handle(Request $request): Response
    {
        $ctx = new Ctx($this->app);

        $match = $this->router->match($request->method, $request->path);

        if ($match !== null) {
            $ctx->route = $match['route'];
            $request = $request->withParams($match['params']);
        }

        $terminal = fn(Request $r): Response => $match === null
            ? $this->notFound($r, $ctx)
            : $this->invoke($match['route'], $r, $ctx);

        try {
            return $this->pipeline($terminal, $ctx)($request);
        } catch (\Throwable $e) {
            // ข้อผิดพลาดไม่ได้วิ่งกลับผ่าน middleware ที่ค้างอยู่ คำตอบนี้จึงไม่มี header
            // ความปลอดภัยติดมาเลย ต้องห่อด้วย SecurityHeaders เองไม่งั้นหน้า error
            // จะไม่มี CSP ซึ่งทำให้พฤติกรรมต่างจากหน้าปกติโดยไม่มีเหตุผล
            return (new SecurityHeaders())->handle(
                $request,
                $ctx,
                fn(Request $r): Response => $this->handleException($e, $r, $ctx),
            );
        }
    }

    /**
     * ห่อ middleware เข้าด้วยกันจากในออกนอก
     *
     * @param callable(Request):Response $terminal
     * @return callable(Request):Response
     */
    private function pipeline(callable $terminal, Ctx $ctx): callable
    {
        $stack = $terminal;

        foreach (array_reverse($this->middleware()) as $middleware) {
            $next = $stack;
            $stack = static fn(Request $r): Response => $middleware->handle($r, $ctx, $next);
        }

        return $stack;
    }

    /** @return list<Middleware> */
    private function middleware(): array
    {
        return [
            new SecurityHeaders(),
            new RateLimit(),
            new SessionMiddleware(),
            new Authenticate(),
            new CsrfProtection(),
            new Authorize(),
            new AuditContext()
        ];
    }

    /**
     * @param Route $route
     * @param Request $request
     * @param Ctx $ctx
     */
    private function invoke(Route $route, Request $request, Ctx $ctx): Response
    {
        // JSON ที่แปลงไม่ได้ต้องถูกปฏิเสธก่อนถึง controller — ไม่งั้นค่าที่ส่งมาจะกลายเป็น
        // "ไม่ได้ส่งมา" เงียบ ๆ แล้วผู้เรียกจะได้ 422 ว่าฟิลด์ขาด ทั้งที่ปัญหาจริงคือ
        // เครื่องหมายจุลภาคเกินมาตัวเดียวใน body
        if ($request->isApiV2() && $request->hasBrokenJson()) {
            return ApiProblem::BadRequest->response('เนื้อหาที่ส่งมาไม่ใช่ JSON ที่ถูกต้อง');
        }

        $controller = new $route->controller($this->app, $ctx, $this->router);

        if (!method_exists($controller, $route->action)) {
            throw new \LogicException("ไม่พบ action {$route->controller}::{$route->action}");
        }

        return $controller->{$route->action}($request);
    }

    /**
     * @param Request $request
     * @param Ctx $ctx
     */
    private function notFound(Request $request, Ctx $ctx): Response
    {
        // path มีอยู่แต่คนละ method → 405 ซึ่งช่วยตอนดีบักมากกว่า 404
        $status = $this->router->pathExists($request->path) ? 405 : 404;

        if ($request->isApiV2()) {
            return $status === 405
                ? ApiProblem::MethodNotAllowed->response('เส้นทางนี้ไม่รองรับวิธีการเรียกแบบนี้')
                : ApiProblem::NotFound->response('ไม่พบเส้นทางที่ร้องขอ');
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => 'ไม่พบเส้นทางที่ร้องขอ'], $status);
        }

        return ErrorPage::response(
            $status,
            $status === 405 ? 'วิธีเรียกไม่ถูกต้อง' : 'ไม่พบหน้าที่ต้องการ',
            $status === 405
                ? 'เส้นทางนี้มีอยู่ แต่ไม่รองรับวิธีการเรียกแบบนี้'
                : 'หน้าที่คุณเรียกไม่มีอยู่ในระบบ',
        );
    }

    /**
     * @param \Throwable $e
     * @param Request $request
     * @param Ctx $ctx
     */
    private function handleException(\Throwable $e, Request $request, Ctx $ctx): Response
    {
        // ข้อผิดพลาดจาก agent มีข้อความไทยที่แสดงให้ผู้ใช้เห็นได้อยู่แล้ว
        $isAgentError = $e instanceof AgentException;

        $this->app->logger()->error($isAgentError ? 'คำสั่งล้มเหลว' : 'ข้อผิดพลาดที่ไม่ได้จัดการ', [
            'request_id' => $request->requestId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'path' => $request->path,
            'user' => $ctx->username()
        ]);

        $status = $isAgentError ? 400 : 500;
        $message = $isAgentError
            ? $e->getMessage()
            : 'เกิดข้อผิดพลาดภายในระบบ กรุณาตรวจสอบ log (รหัสอ้างอิง: '.$request->requestId.')';

        // REST API v2 ต้องได้รหัสที่เครื่องอ่านได้และ status ที่ตรงความหมายจริง
        //
        // ของเดิมยุบข้อผิดพลาดจาก agent ทุกชนิดเป็น 400 เหมือนกันหมด ทำให้ฝั่งเรียก
        // แยกไม่ออกว่า "ค่าที่ส่งมาผิด" (แก้แล้วลองใหม่ได้) ต่างจาก "agent ไม่ตอบ"
        // (ต้องไปดูว่าบริการล่มหรือเปล่า) ทั้งที่สองอย่างนี้ต้องทำคนละอย่างกันสิ้นเชิง
        if ($request->isApiV2()) {
            $problem = $isAgentError
                ? ApiProblem::fromAgentException($e)
                : ApiProblem::InternalError;

            return $problem->response($message);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }

        // ไม่แสดง $message ตรง ๆ กับผู้ใช้ที่เปิดผ่านเบราว์เซอร์เมื่อเป็นข้อผิดพลาด
        // ภายใน — ข้อความนั้นมีไว้ให้คนดู log · รหัสอ้างอิงคือสิ่งที่ผู้ใช้ต้องบอกต่อ
        return ErrorPage::response(
            $status,
            $isAgentError ? 'ดำเนินการไม่สำเร็จ' : 'เกิดข้อผิดพลาดภายในระบบ',
            $isAgentError ? $message : 'รหัสอ้างอิง: '.$request->requestId,
        );
    }
}
