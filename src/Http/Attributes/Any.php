<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route(['GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS'], $path, ...)] 的语法糖。
 *
 * @example #[Any('/webhook')]  public function receive() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Any extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], $path, $name, $middleware);
    }
}
