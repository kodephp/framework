<?php

declare(strict_types=1);

namespace Kode\Framework\Health;

use Kode\Cache\CacheManager;
use Kode\Database\Db\Db;
use Kode\Queue\Queue;
use Psr\Container\ContainerInterface;

/**
 * 就绪探针聚合器（企业级健康检查）
 *
 * 框架内置三类组件探针（db / cache / queue），并在 config/health.php 中以布尔开关启用；
 * 也支持任意自定义闭包探针（返回 'ok' / 'error: ...' / 'not_configured'）。
 *
 * 用途：
 *  - /health/ready（k8s readinessProbe）：所有启用探针健康才 200，否则 503，
 *    使依赖未就绪时流量被摘除。
 *  - /health（聚合巡检）：返回各组件明细，便于人工 / 监控查看。
 *
 * 设计立场：探针默认「未配置即 not_configured（不计入失败）」，避免未用某组件时误报。
 */
final class HealthChecker
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * 执行全部启用探针。
     *
     * @return array{healthy: bool, checks: array<string, string>}
     */
    public function check(): array
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
            $result = is_callable($spec)
                ? $this->run($spec)
                : $this->builtin((string) $name);
            $results[$name] = $result;
            if (str_starts_with($result, 'error')) {
                $healthy = false;
            }
        }

        $results['app'] = 'ok';

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
     * 内置探针：db / cache / queue。
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
}
