<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/** One route — binds a path to a controller and the permission it requires */
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
