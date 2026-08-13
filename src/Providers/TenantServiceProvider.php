<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Tenant\HeaderTenantResolver;
use Kode\Framework\Tenant\SubdomainTenantResolver;
use Kode\Framework\Tenant\TenantMiddleware;
use Kode\Framework\Tenant\TenantResolver;

/**
 * 多租户服务提供者（kode/context 薄壳委托）。
 *
 * 仅提供「租户上下文原语」，不做任何存储隔离：
 *  - 按 config/tenant.php 构造 TenantResolver（内置 header / subdomain 或应用自定义类）；
 *  - 绑定 TenantMiddleware 单例（解析器 + 回退租户），由 HttpServiceProvider 接进 HTTP 管道；
 *  - 业务侧用 tenant() 助手读取当前请求租户（见 src/Support/helpers.php）。
 */
final class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(TenantResolver::class, function (): ?TenantResolver {
            return $this->makeResolver();
        });

        $this->container->singleton(TenantMiddleware::class, function (): TenantMiddleware {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('tenant', []);

            /** @var TenantResolver|null $resolver */
            $resolver = $this->container->get(TenantResolver::class);

            return new TenantMiddleware($resolver, $config['default'] ?? null);
        });

        $this->container->alias('tenant.middleware', TenantMiddleware::class);
    }

    /**
     * 依据 config('tenant.resolver') 构造解析器实例。
     *
     * @return TenantResolver|null null 表示「不解析」，仅用 default 回退。
     */
    private function makeResolver(): ?TenantResolver
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config('tenant', []);
        $spec = $config['resolver'] ?? null;

        if ($spec === null || $spec === '') {
            return null;
        }

        if ($spec === 'header') {
            return new HeaderTenantResolver((string) ($config['header']['name'] ?? 'X-Tenant-Id'));
        }

        if ($spec === 'subdomain') {
            return new SubdomainTenantResolver((string) ($config['subdomain']['base_domain'] ?? ''));
        }

        // 自定义 TenantResolver 类名（应用实现）
        if (class_exists($spec) && is_a($spec, TenantResolver::class, true)) {
            return $this->container->make($spec);
        }

        throw new \InvalidArgumentException(
            "tenant.resolver 非法：期望 'header' | 'subdomain' | 实现 " . TenantResolver::class . " 的类，收到 {$spec}",
        );
    }
}
