<?php

declare (strict_types = 1);

namespace Phpcp\Controller;

use Phpcp\Agent\Client;
use Phpcp\Kernel\App;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Response;
use Phpcp\Kernel\Router;

/**
 * The base every controller shares — consolidates what every route needs into one place
 *
 * Down to two subclasses after the HTML-based UI was removed: `ApiController`
 * (every `/api/v2/*` route) and `SpaController` (the single shell file) ·
 * the methods that used to exist for HTML pages — `view()`, `safeNext()` — were deleted along with it, nothing calls them anymore
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
