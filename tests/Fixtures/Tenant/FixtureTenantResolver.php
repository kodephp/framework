<?php

declare(strict_types=1);

namespace Kode\Framework\Tests\Fixtures\Tenant;

use Kode\Framework\Tenant\TenantResolver;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 测试用自定义租户解析器：从查询参数 ?tenant= 解析（同时验证应用可注入自有 TenantResolver）。
 */
final class FixtureTenantResolver implements TenantResolver
{
    public function resolve(ServerRequestInterface $request): ?string
    {
        // 兼容「请求未自动解析查询参数」的情况：直接解析 URI 查询串。
        parse_str($request->getUri()->getQuery(), $query);
        $value = $query['tenant'] ?? ($request->getQueryParams()['tenant'] ?? '');

        return $value === '' ? null : (string) $value;
    }
}
