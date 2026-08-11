<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route('PUT', $path, ...)] 的语法糖。
 *
 * @example #[Put('/users/{id:\\d+}')]  public function update() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Put extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct('PUT', $path, $name, $middleware);
    }
}
