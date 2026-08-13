<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

final class SecurityController extends ApiController
{
    public function scan(Request $request): Response
    {
        $data = $this->agent()->data('security.scan', [], $this->ctx->actor($request));

        $checks = $data['checks'] ?? [];
        unset($data['checks']);

        // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${status_tone}` ได้ตรง ๆ
        $checks = array_map(static function (array $check): array {
            $check['status_tone'] = match ($check['status'] ?? '') {
                'pass' => 'ok',
                'fail' => 'danger',
                'warn' => 'warn',
                default => 'muted',
            };

            return $check;
        }, $checks);

        return $this->ok($checks, $data);
    }
}
