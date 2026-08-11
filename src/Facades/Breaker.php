<?php

declare(strict_types=1);

namespace Kode\Framework\Facades;

use Kode\Core\Facade;

/**
 * 熔断器门面：Breaker::run('svc', fn () => ..., fallback: fn () => ...)。
 *
 * 解析目标为 Kode\Framework\Resilience\Breaker（经容器单例）。
 */
final class Breaker extends Facade
{
    protected static function id(): string
    {
        return \Kode\Framework\Resilience\Breaker::class;
    }
}
