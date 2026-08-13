<?php

declare(strict_types=1);

namespace Kode\Framework\Health;

use Kode\Cache\CacheManager;
use Kode\Database\Db\Db;
use Kode\Queue\Queue;
use Psr\Container\ContainerInterface;

/**
 * 就绪探针聚合器（企业级健康检查，v0.8.14 增强为「能力感知」）。
 *
 * 三类探测合一：
 *  1) 配置驱动探针（config/health.php 的 `checks`）：db / cache / queue 布尔开关 + 任意自定义闭包；
 *  2) 能力感知探针（自动）：对框架已接线的企业级子系统做只读可达性检查——
 *     config_center / service_discovery / tracing / tenant_storage；未接线即 `not_configured`（不计入失败）；
 *  3) app 自身：永远 `ok`。
 *
 * 用途（配合框架内置 /health、/health/live、/health/ready、/ping 端点与 health:check 命令）：
 *  - readinessProbe：所有探针（含自定义）健康才 200，任一 error 即 503，依赖未就绪时流量被摘除；
 *  - 人工 / 监控巡检：聚合视图返回各组件明细。
 *
 * 设计立场：探针默认「未配置 / 未接线即 not_configured（不计入失败）」，避免未用某组件时误报；
 * 能力感知探针只读（不 mutate 配置 / 不 flush 追踪），对就绪检查零副作用。
 */
final class HealthChecker
{
    /**
     * 能力感知探针名（自动纳入；config/health.php 中以同名键 false 可关闭）。
     */
    private const CAPABILITY_NAMES = [
        'config_center',
        'service_discovery',
        'tracing',
        'tenant_storage',
    ];

    /**
     * @param array<string, mixed> $config
     * @param ?\Closure(HealthChecked): void $dispatcher 健康结果派发回调（由 Provider 注入，解耦事件系统启动顺序）
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?ContainerInterface $container = null,
        private readonly ?\Closure $dispatcher = null,
    ) {
    }

    /**
     * 执行全部探针。
     *
     * @return array{healthy: bool, checks: array<string, string>}
     */
    public function check(string $mode = 'aggregate'): array
    {
        /** @var array<string, string> $results */
        $results = [];
        $healthy = true;

        /** @var array<string, mixed> $checks */
        $checks = (array) ($this->config['checks'] ?? []);
        foreach ($checks as $name => $spec) {
            if ($spec === false) {
                continue;
            }
            // 能力感知探针名交由下方自动探测处理（尊重 false 关闭）。
            if (in_array($name, self::CAPABILITY_NAMES, true)) {
                continue;
            }
            $result = is_callable($spec)
                ? $this->run($spec)
                : $this->builtin((string) $name);
            $results[$name] = $result;
            if (str_starts_with($result, 'error')) {
                $healthy = false;
            }
        }

        // 能力感知探针：已接线的企业级子系统自动纳入（未接线不计入失败；config 同名 false 可关闭）。
        foreach ($this->capabilityProbes() as $name => $method) {
            if (array_key_exists($name, $results)) {
                continue;
            }
            if (array_key_exists($name, $checks) && $checks[$name] === false) {
                continue;
            }
            $result = $this->{$method}();
            $results[$name] = $result;
            if (str_starts_with($result, 'error')) {
                $healthy = false;
            }
        }

        $results['app'] = 'ok';

        $this->dispatch($healthy, $results, $mode);

        return ['healthy' => $healthy, 'checks' => $results];
    }

    /**
     * @param callable $probe
     */
    private function run(callable $probe): string
    {
        try {
            $r = $probe($this->container);
            return is_string($r) ? $r : 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 内置探针：db / cache / queue（由 config/health.php 的 checks 键驱动）。
     */
    private function builtin(string $name): string
    {
        try {
            return match ($name) {
                'db' => $this->db(),
                'cache' => $this->cache(),
                'queue' => $this->queue(),
                default => 'not_configured',
            };
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    private function db(): string
    {
        if (Db::getConnections() === []) {
            return 'not_configured';
        }

        Db::select('SELECT 1');

        return 'ok';
    }

    private function cache(): string
    {
        if ($this->container === null || !$this->container->has(CacheManager::class)) {
            return 'not_configured';
        }

        /** @var CacheManager $cache */
        $cache = $this->container->get(CacheManager::class);
        $key = '__kode_health_check__';
        $cache->set($key, 1, 5);
        if ($cache->get($key) === null) {
            return 'error: cache get 失败';
        }

        return 'ok';
    }

    private function queue(): string
    {
        if ($this->container === null || !$this->container->has(Queue::class)) {
            return 'not_configured';
        }

        /** @var Queue $queue */
        $queue = $this->container->get(Queue::class);
        // 轻量连通性探测：尝试获取连接（不真正消费）。
        $queue->connection();

        return 'ok';
    }

    /**
     * 能力感知探针映射（name => 本类方法）。仅对「已接线」的子系统做只读可达性检查。
     *
     * @return array<string, string>
     */
    private function capabilityProbes(): array
    {
        return [
            'config_center'     => 'probeConfigCenter',
            'service_discovery' => 'probeServiceDiscovery',
            'tracing'           => 'probeTracing',
            'tenant_storage'    => 'probeTenantStorage',
        ];
    }

    /**
     * 配置中心：管理器具名即视为可达（只读 sources()，不 mutate 配置）。
     */
    private function probeConfigCenter(): string
    {
        if (config_center() === null) {
            return 'not_configured';
        }
        try {
            config_center()->sources();

            return 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 服务发现：管理器就绪即视为可达（只读 stats()，不发起网络发现）。
     */
    private function probeServiceDiscovery(): string
    {
        if (service() === null) {
            return 'not_configured';
        }
        try {
            service()->stats();

            return 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 分布式追踪：管理器就绪（导出器已注入）即视为可达（只读 buffered()，不 flush）。
     */
    private function probeTracing(): string
    {
        if (tracer() === null) {
            return 'not_configured';
        }
        try {
            tracer()->buffered();

            return 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 租户存储隔离：管理器就绪即视为可达（不真正切库）。
     */
    private function probeTenantStorage(): string
    {
        if (tenant_storage() === null) {
            return 'not_configured';
        }

        return 'ok';
    }

    private function dispatch(bool $healthy, array $checks, string $mode): void
    {
        if ($this->dispatcher === null) {
            return;
        }
        ($this->dispatcher)(new HealthChecked($healthy, $checks, $mode));
    }
}
