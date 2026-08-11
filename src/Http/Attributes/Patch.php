<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route('PATCH', $path, ...)] 的语法糖。
 *
 * @example #[Patch('/users/{id:\\d+}')]  public function patch() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Patch extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct('PATCH', $path, $name, $middleware);
    }
}
