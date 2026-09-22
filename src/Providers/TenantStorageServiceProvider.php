<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Tenant\Storage\StaticTenantStorageResolver;
use Kode\Framework\Tenant\Storage\TenantConnectionResolver;
use Kode\Framework\Tenant\Storage\TenantStorageManager;
use Kode\Framework\Tenant\Storage\TenantStorageMiddleware;

/**
 * 多租户存储隔离服务提供者（薄壳层，独立可禁用）。
 *
 * 仅当 config('tenant.storage.enabled') = true 时接线；否则不注册任何中间件 / 管理器，
 * 与租户上下文原语（TenantServiceProvider）解耦——后者只解析租户，本服务提供「按租户切库」。
 *
 * 绑定：
 *  - TenantConnectionResolver：按 storage.strategy 选择内置 StaticTenantStorageResolver，
 *    或注入自定义 TenantConnectionResolver 实现（<FQCN>）；
 *  - TenantStorageManager：持有解析器 + 默认连接名 + 事件派发闭包；
 *  - TenantStorageMiddleware：由 HttpServiceProvider 拉取并挂入 HTTP 管道（TenantMiddleware 内层）。
 */
final class TenantStorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array<string, mixed> $tenant */
        $tenant = (array) $this->config('tenant', []);
        /** @var array<string, mixed> $storage */
        $storage = (array) ($tenant['storage'] ?? []);

        if (empty($storage['enabled'])) {
            return;
        }

        /** @var array<string, mixed> $db */
        $db = (array) $this->config('database', []);
        $defaultConnection = (string) ($db['default'] ?? 'mysql');

        $strategy = (string) ($storage['strategy'] ?? 'shared');

        // 自定义解析器：strategy 为实现了 TenantConnectionResolver 的 FQCN 时直接 make。
        if ($strategy !== '' && class_exists($strategy) && is_a($strategy, TenantConnectionResolver::class, true)) {
            $resolver = $this->container->make($strategy);
        } else {
            // 模板连接必须「要么没配（跟随默认），要么配得对」：静默借用别的连接配置等于
            // 让租户库拿着另一套凭证跑，隔离失效且无日志可查——所以配错一律启动即失败。
            $connections = (array) ($db['connections'] ?? []);
            $effective = StaticTenantStorageResolver::effectiveTemplate($storage['template'] ?? '', $defaultConnection);

            if (!array_key_exists($effective, $connections)) {
                throw new \RuntimeException(sprintf(
                    '租户存储配置错误：连接 "%s" 不在 config/database.php 的 connections 中（可用：%s）。'
                    . '它取自 tenant.storage.template，留空则跟随 database.default。',
                    $effective,
                    $connections === [] ? '无' : implode(', ', array_keys($connections)),
                ));
            }

            $templateConfig = (array) $connections[$effective];

            $resolver = new StaticTenantStorageResolver(
                $strategy === '' ? 'shared' : $strategy,
                $effective,
                $templateConfig,
                $defaultConnection,
                (string) ($storage['prefix'] ?? 'tnt_'),
                (array) ($storage['map'] ?? []),
                (string) ($storage['on_missing'] ?? 'fallback'),
            );
        }

        $this->container->singleton(TenantConnectionResolver::class, static fn(): TenantConnectionResolver => $resolver);

        $this->container->singleton(TenantStorageManager::class, function () use ($resolver, $defaultConnection, $storage): TenantStorageManager {
            return new TenantStorageManager(
                $resolver,
                $defaultConnection,
                fn (object $event): object => event($event),
                $storage,
            );
        });
        $this->container->alias('tenant.storage', TenantStorageManager::class);

        $this->container->singleton(TenantStorageMiddleware::class, function (): TenantStorageMiddleware {
            /** @var TenantStorageManager $manager */
            $manager = $this->container->get(TenantStorageManager::class);

            return new TenantStorageMiddleware($manager);
        });
    }
}
