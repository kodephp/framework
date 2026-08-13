<?php

declare(strict_types=1);

namespace Kode\Framework\Feature;

use Kode\Core\Config\Config;

/**
 * Feature Flags 管理器（框架原生薄实现）。
 *
 * 判定模型（与 config/feature.php 注释一致）：
 *  - 动态 resolver 优先（registerResolver 接入 DB/Redis/配置中心/租户覆盖）；
 *  - 未配置 flag → 回落 feature.default；
 *  - enabled=false 直接关；enabled=true 结合 rollout 灰度。
 *
 * 分桶：bucket(name, key) = crc32(name:key) % 100，对相同 key 稳定，
 * 因此同一用户/租户在灰度窗口内命中一致。
 */
final class FeatureManager
{
    /** @var list<callable(string,?string):?bool> 动态解析器，优先级高于 config */
    private array $resolvers = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * 判定某 flag 是否对当前上下文（可选 key）开启。
     */
    public function isEnabled(string $name, ?string $key = null): bool
    {
        foreach ($this->resolvers as $resolver) {
            $result = $resolver($name, $key);
            if ($result !== null) {
                return $result;
            }
        }

        /** @var array<string, array{enabled?: bool, rollout?: int, description?: string}> $flags */
        $flags = (array) $this->config->get('feature.flags', []);
        $cfg = $flags[$name] ?? null;

        if ($cfg === null) {
            return (bool) $this->config->get('feature.default', false);
        }

        if (isset($cfg['enabled']) && $cfg['enabled'] === false) {
            return false;
        }

        $enabled = !empty($cfg['enabled']);
        $rollout = (int) ($cfg['rollout'] ?? 100);
        if ($rollout >= 100) {
            return $enabled;
        }
        if ($rollout <= 0) {
            return false;
        }

        return $this->bucket($name, $key) < $rollout;
    }

    /**
     * 注册动态解析器（返回值非 null 即短路判定）。
     *
     * 典型用途：从 Redis/DB/配置中心读取实时开关、按租户覆盖、A-B 实验分流。
     * 多个 resolver 按注册顺序求值，第一个返回非 null 者生效。
     *
     * @param callable(string, ?string): ?bool $resolver
     */
    public function registerResolver(callable $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * 单 flag 状态快照（含分桶结果，便于排查灰度命中）。
     *
     * @return array{name: string, enabled: bool, configured: bool, rollout: ?int, key: ?string}
     */
    public function status(string $name, ?string $key = null): array
    {
        /** @var array<string, array{rollout?: int}> $flags */
        $flags = (array) $this->config->get('feature.flags', []);
        $cfg = $flags[$name] ?? null;

        return [
            'name'       => $name,
            'enabled'    => $this->isEnabled($name, $key),
            'configured' => $cfg !== null,
            'rollout'    => $cfg === null ? null : (int) ($cfg['rollout'] ?? 100),
            'key'        => $key,
        ];
    }

    /**
     * 全部已声明 flag 的状态（按当前 key 分桶后的实际生效值）。
     *
     * @return array<string, array{name: string, enabled: bool, configured: bool, rollout: ?int, key: ?string}>
     */
    public function all(?string $key = null): array
    {
        /** @var array<string, mixed> $flags */
        $flags = (array) $this->config->get('feature.flags', []);
        $out = [];
        foreach (array_keys($flags) as $name) {
            $out[$name] = $this->status((string) $name, $key);
        }

        return $out;
    }

    /**
     * 稳定分桶：相同 (name, key) 永远落在同一桶，保证灰度不抖动。
     */
    private function bucket(string $name, ?string $key): int
    {
        return crc32($name . ':' . ($key ?? '')) % 100;
    }
}
