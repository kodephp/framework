<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Support;

/**
 * 路由键归一化工具（v1.0.0 新增，供限流 / 熔断等按「路由」维度进行状态隔离时共用）。
 *
 * 目的：把动态路径（/users/42、/users/0189abc-....）归一为模板形态（/users/{id}、
 * /users/{uuid}），使同一路由的不同参数实例共享同一个状态键。
 *
 * 此前限流（RateLimitMiddleware::routeKey）与熔断（CircuitBreakerMiddleware 按原始 path
 * 取键）各写一套，熔断直接用原始 path 导致 `/users/42` 与 `/users/999` 各自独立熔断，
 * 动态路径可被遍历绕过熔断（H8）。此处收敛为单一实现，两处共用。
 */
final class RouteKey
{
    /**
     * 把请求路径中的动态段归一为模板占位符。
     */
    public static function normalize(string $path): string
    {
        return (string) preg_replace(
            '/\/\d+(?=\/|$)/',
            '/{id}',
            preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/{uuid}', $path)
        );
    }
}