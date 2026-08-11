<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Attributes;

use Attribute;

/**
 * 等价于 #[Route('POST', $path, ...)] 的语法糖。
 *
 * @example #[Post('/users')]  public function store() {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Post extends Route
{
    public function __construct(string $path = '', ?string $name = null, array $middleware = [])
    {
        parent::__construct('POST', $path, $name, $middleware);
    }
}
