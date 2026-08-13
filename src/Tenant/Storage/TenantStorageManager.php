<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

use Closure;
use Kode\Database\Db\Db;

/**
 * 租户存储隔离管理器（薄壳层核心）。
 *
 * 职责：
 *  - resolve：把租户标识解析为连接名（借 {@see TenantConnectionResolver}，并懒注册到 kode/database）；
 *  - boot：在请求级把 kode/database 默认连接切换到租户连接，返回「切换前的默认连接名」；
 *  - restore：请求结束把默认连接恢复为切换前的值，绝不跨请求串扰；
 *  - currentConnection：当前请求级激活的租户连接名（null = 未隔离）。
 *
 * 并发模型：kode/process 下单 worker 一次处理一个请求，boot/restore 在中间件 try/finally
 * 中成对出现，连接切换严格限定在单个请求 scope 内。kode/fibers active runtime 下，若需更严格
 * 的「逐查询」隔离，业务侧可用 {@see connectionName()} 取连接名后显式 Db::connection($name)->table(...)
 * （本管理器同样暴露连接名供此用途）。
 *
 * 设计立场：框架只做「解析 → 切换 → 恢复 → 事件」，不内置任何租户元数据存储；
 * 真实后端实现 TenantConnectionResolver 注入即零改动复用——与配置中心 / 服务发现 / Feature Flags 同构。
 */
final class TenantStorageManager
{
    /** @var array<string, string> 进程级解析缓存：tenantId => 连接名（addConnection 幂等去重）。 */
    private array $cache = [];

    /** @var string|null 当前请求级激活的租户连接名（restore 后清空）。 */
    private ?string $activeConnection = null;

    /**
     * @param TenantConnectionResolver $resolver            租户连接解析器
     * @param string                   $defaultConnectionName 默认连接名（恢复目标）
     * @param Closure(object):object    $dispatcher          事件派发闭包（默认经 event() 助手）
     * @param array<string,mixed>       $config               storage 段配置（仅作上下文透传）
     */
    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly string $defaultConnectionName,
        private readonly Closure $dispatcher,
        private readonly array $config = [],
    ) {
    }

    /**
     * 解析并返回某租户的连接名（懒注册到 kode/database）。
     *
     * 返回 null 表示「不隔离」（shared 策略或 map 缺失且 on_missing=fallback）。
     */
    public function connectionName(string $tenantId): ?string
    {
        $config = $this->resolver->resolve($tenantId);

        if ($config === null) {
            return null;
        }

        return $this->register($tenantId, $config);
    }

    /**
     * 请求级切换：把默认 DB 连接切到租户连接。
     *
     * @return string|null 切换前的默认连接名；null 表示未发生切换（不隔离）。
     */
    public function boot(string $tenantId): ?string
    {
        $name = $this->connectionName($tenantId);

        if ($name === null) {
            return null;
        }

        $previous = Db::getDefaultConnection();
        Db::setDefaultConnection($name);
        $this->activeConnection = $name;

        ($this->dispatcher)(new TenantStorageSwitched($tenantId, $name));

        return $previous;
    }

    /**
     * 请求级恢复：把默认连接还原为切换前的值（在中间件 finally 中调用）。
     */
    public function restore(?string $previous): void
    {
        if ($previous !== null) {
            Db::setDefaultConnection($previous);
        }

        $this->activeConnection = null;
    }

    /**
     * 当前请求级激活的租户连接名（null = 未隔离 / 已恢复）。
     */
    public function currentConnection(): ?string
    {
        return $this->activeConnection;
    }

    /**
     * 解析并注册连接（幂等），返回连接名。
     *
     * @param array<string, mixed> $config
     */
    private function register(string $tenantId, array $config): string
    {
        if (isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }

        // map 策略复用已注册连接名：不再 addConnection，直接采用。
        if (isset($config[StaticTenantStorageResolver::USE_CONNECTION])) {
            $name = (string) $config[StaticTenantStorageResolver::USE_CONNECTION];
            $this->cache[$tenantId] = $name;

            return $name;
        }

        // database/schema/map-覆盖 策略：派生连接名并注册到 kode/database（懒加载，不立即连库）。
        $name = 'tenant_' . StaticTenantStorageResolver::sanitize($tenantId);
        Db::addConnection($name, $config);
        $this->cache[$tenantId] = $name;

        return $name;
    }
}
