<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Controllers;

use Kode\Limiting\Attribute\RateLimit;

/**
 * 限流测试夹具：类级规则 + 方法级规则叠加，验证属性读取器。
 */
#[RateLimit(capacity: 50, rate: 2.0, key: 'fixture:{ip}')]
final class RateLimitedController
{
    #[RateLimit(capacity: 3, rate: 1.0, key: 'fixture:list:{ip}')]
    public function index(): void
    {
    }

    public function show(): void
    {
    }
}
