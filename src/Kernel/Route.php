<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/** เส้นทางหนึ่งเส้น — ผูก path เข้ากับ controller และ permission ที่ต้องใช้ */
final readonly class Route
{
    public function __construct(
        public string $method,
        public string $path,
        public string $controller,
        public string $action,
        public ?string $permission,
        public string $name = '',
        public string $title = '',
        /** @var list<array{label:string,url:string}> */
        public array $breadcrumb = [],
        public string $navKey = '',
    ) {
    }
}
