<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route('GET', $path, ...)] 的语法糖。
 *
 * @example #[Get('/users')]  public function index() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Get extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct('GET', $path, $name, $middleware);
    }
}
