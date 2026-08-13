<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 从请求头解析租户（默认 X-Tenant-Id）。
 *
 * 适合前端网关 / BFF 已注入租户标识的场景。
 */
final class HeaderTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly string $header = 'X-Tenant-Id',
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $value = $request->getHeaderLine($this->header);

        return $value === '' ? null : $value;
    }
}
