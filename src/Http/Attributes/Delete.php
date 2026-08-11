<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route('DELETE', $path, ...)] 的语法糖。
 *
 * @example #[Delete('/users/{id:\\d+}')]  public function destroy() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Delete extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct('DELETE', $path, $name, $middleware);
    }
}
