<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 从子域名解析租户（取主机名的第一段标签）。
 *
 * 例如 acme.example.com → "acme"；example.com（无子域）→ null。
 * 适合「每租户独立子域」的 SaaS 形态。
 */
final class SubdomainTenantResolver implements TenantResolver
{
    public function __construct(
        private readonly string $baseDomain = '', // 形如 "example.com"；为空则不剔除
    ) {
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $host = $request->getUri()->getHost();
        if ($host === '') {
            return null;
        }

        // 去掉基础域名，保留子域部分（如 acme.example.com → acme）
        if ($this->baseDomain !== '' && str_ends_with($host, $this->baseDomain)) {
            $host = substr($host, 0, -strlen($this->baseDomain));
            $host = rtrim($host, '.');
        }

        // 剥离后为空（即只有基础域名）→ 无租户
        if ($host === '') {
            return null;
        }

        // 取第一段标签作为租户（a.b.example.com → a）
        $sub = explode('.', $host)[0];

        return $sub === '' ? null : $sub;
    }
}
