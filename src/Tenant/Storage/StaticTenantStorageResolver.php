<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

/**
 * 内置静态租户连接解析器（薄壳层内置后端）。
 *
 * 支持四种静态策略：
 *  - shared   不隔离（resolve 返回 null，TenantStorageManager 不做连接切换）；
 *  - database 每租户独立库，database = prefix + sanitize(tenantId)，基于 template 连接派生；
 *  - schema   语义同 database（thin-shell 同样落到 database 命名，应用可叠加 schema 策略）；
 *  - map      显式映射：tenant id => 已注册连接名(string) 或 连接配置覆盖(array)。
 *
 * 真实后端（中心租户表 / 配置中心）实现 {@see TenantConnectionResolver} 注入即可，
 * 本类仅作开箱即用的默认实现与「自定义解析器」的范本。
 */
final class StaticTenantStorageResolver implements TenantConnectionResolver
{
    /**
     * 复用已注册连接名（map 策略中 tenant id => 'connection_name' 时返回此标记）。
     *
     * @internal
     */
    public const USE_CONNECTION = '__use_connection';

    /**
     * @param string              $strategy            策略：shared|database|schema|map
     * @param string              $templateName        模板连接名（取自 config/database.connections）
     * @param array<string,mixed> $templateConfig      模板连接配置（克隆后派生）
     * @param string              $defaultConnectionName 默认连接名（仅作回退语义参考）
     * @param string              $prefix              database/schema 策略的库名前缀
     * @param array<string,mixed> $map                 map 策略的显式映射
     * @param string              $onMissing           fallback|abort（缺失映射时的行为）
     */
    public function __construct(
        private readonly string $strategy,
        private readonly string $templateName,
        private readonly array $templateConfig,
        private readonly string $defaultConnectionName,
        private readonly string $prefix,
        private readonly array $map,
        private readonly string $onMissing,
    ) {
    }

    public function resolve(string $tenantId): ?array
    {
        return match ($this->strategy) {
            'shared' => null,
            'database', 'schema' => $this->derive($tenantId),
            'map' => $this->fromMap($tenantId),
            default => $this->missing($tenantId),
        };
    }

    /**
     * database/schema 策略：克隆模板连接，库名 = prefix + sanitize(租户标识)。
     *
     * @return array<string, mixed>
     */
    private function derive(string $tenantId): array
    {
        $config = $this->templateConfig;
        $config['database'] = $this->prefix . self::sanitize($tenantId);

        return $config;
    }

    /**
     * map 策略：租户在映射中则复用连接名或合并覆盖；否则按 on_missing 处理。
     *
     * @return array<string, mixed>|null
     */
    private function fromMap(string $tenantId): ?array
    {
        if (!array_key_exists($tenantId, $this->map)) {
            return $this->missing($tenantId);
        }

        $entry = $this->map[$tenantId];

        // 字符串 = 复用已注册连接名（如 'tenant_acme' 已通过 addConnection 注册）。
        if (is_string($entry)) {
            return [self::USE_CONNECTION => $entry];
        }

        // 数组 = 在模板连接基础上做覆盖（如覆盖 host/database/credentials）。
        if (is_array($entry)) {
            return array_merge($this->templateConfig, $entry);
        }

        return $this->missing($tenantId);
    }

    /**
     * 缺失映射时的行为：fallback 返回 null（沿用默认），abort 抛 404 级异常。
     *
     * @return array<string, mixed>|null
     */
    private function missing(string $tenantId): ?array
    {
        if ($this->onMissing === 'abort') {
            throw new TenantStorageUnresolved($tenantId);
        }

        return null;
    }

    /**
     * 把租户标识转为安全连接 / 库名片段（仅保留字母数字与下划线）。
     */
    public static function sanitize(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $id) ?? 'x';
    }
}
