<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Http\RateLimit\Algorithm;
use Kode\Framework\Http\RateLimit\LimiterFactory;
use Kode\Framework\Http\RateLimit\RateLimitAttributeReader;
use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Limiter;
use Kode\Limiting\RateLimiterInterface;

/**
 * 限流服务提供者（kode/limiting，PHP 8.3+）
 *
 * 依据 config/limiting.php 构建默认 Limiter 单例并绑定到容器，
 * 门面 RateLimit / 助手 rateLimit() 与全局 RateLimitMiddleware 均复用它。
 *
 * 支持 memory / apcu / redis（standalone·sentinel·cluster）/ memcached / pdo
 * 多种存储后端；把 driver 改为 redis 即得到分布式、跨进程/跨机共享的限流。
 */
final class LimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = (array) $this->config('limiting', []);

        $this->container->singleton(Limiter::class, function () use ($config): Limiter {
            return $this->build($config);
        });

        // 限流中间件依赖的工厂（按规则 + 统一存储配置合成 Limiter，按签名缓存连接）。
        $this->container->singleton(LimiterFactory::class, fn(): LimiterFactory => new LimiterFactory($config));

        $this->container->singleton(RateLimitAttributeReader::class, fn(): RateLimitAttributeReader => new RateLimitAttributeReader());

        $this->container->alias(RateLimiterInterface::class, Limiter::class);
        $this->container->alias('rate_limit', Limiter::class);
    }

    /**
     * 由框架统一配置构建默认限流器。
     *
     * @param array<string, mixed> $config
     */
    private function build(array $config): Limiter
    {
        $driver = (string) ($config['driver'] ?? 'memory');
        // 全局兜底限流配置已收敛到 global 子块（默认关闭），此处优先读 global，
        // 并保留对旧版顶层 capacity/rate/algorithm 的兼容回退；默认额度由 10 提至 1000，
        // 避免「未声明 #[RateLimit] 时显式调用 rateLimit() 门面」被压到极低额度。
        $limiterCfg = $config['global'] ?? [];
        $type = Algorithm::fromName((string) ($limiterCfg['algorithm'] ?? $config['algorithm'] ?? 'token_bucket'));
        $capacity = (int) ($limiterCfg['capacity'] ?? $config['capacity'] ?? 1000);
        $rate = (float) ($limiterCfg['rate'] ?? $config['rate'] ?? 1.0);

        return match ($driver) {
            'apcu' => Limiter::apcu($type, $capacity, $rate),
            'memcached' => Limiter::memcached(
                $type,
                $capacity,
                $rate,
                // 修复（v0.8.52）：旧实现误读 redis.host/port——memcached 客户端会连到
                // Redis 主机的 11211 端口，专属配置段被完全忽略。
                (string) ($config['memcached']['host'] ?? '127.0.0.1'),
                (int) ($config['memcached']['port'] ?? 11211)
            ),
            'pdo' => Limiter::pdo(
                $type,
                $capacity,
                $rate,
                (string) ($config['pdo']['dsn'] ?? 'sqlite::memory:'),
                isset($config['pdo']['username']) ? (string) $config['pdo']['username'] : null,
                isset($config['pdo']['password']) ? (string) $config['pdo']['password'] : null,
                (string) ($config['pdo']['table'] ?? 'limiting')
            ),
            'redis' => Limiter::redis(
                $type,
                $capacity,
                $rate,
                (string) ($config['redis']['host'] ?? '127.0.0.1'),
                (int) ($config['redis']['port'] ?? 6379),
                $config['redis']['password'] ?? null,
                (int) ($config['redis']['database'] ?? 0),
                $this->redisMode($config),
                $this->sentinels($config),
                (string) ($config['redis']['master_name'] ?? 'mymaster'),
                $this->clusterNodes($config)
            ),
            default => $this->memory($type, $capacity, $rate),
        };
    }

    private function memory(LimiterType $type, int $capacity, float $rate): Limiter
    {
        return match ($type) {
            LimiterType::SLIDING_WINDOW => Limiter::slidingWindow($capacity, $rate),
            LimiterType::SLIDING_WINDOW_COUNTER => Limiter::slidingWindowCounter($capacity, (int) $rate),
            LimiterType::COUNTER => Limiter::counter($capacity, (int) $rate),
            LimiterType::LEAKY_BUCKET => Limiter::leakyBucket($capacity, $rate),
            default => Limiter::tokenBucket($capacity, $rate),
        };
    }

    private function redisMode(array $config): RedisMode
    {
        return match ((string) ($config['redis']['mode'] ?? 'standalone')) {
            'sentinel' => RedisMode::SENTINEL,
            'cluster' => RedisMode::CLUSTER,
            default => RedisMode::STANDALONE,
        };
    }

    /**
     * @return list<string>
     */
    private function sentinels(array $config): array
    {
        $raw = $config['redis']['sentinels'] ?? ['127.0.0.1:26379'];

        return is_array($raw) ? array_values(array_map('strval', $raw)) : ['127.0.0.1:26379'];
    }

    /**
     * @return list<string>
     */
    private function clusterNodes(array $config): array
    {
        $raw = $config['redis']['cluster_nodes'] ?? ['127.0.0.1:7000'];

        return is_array($raw) ? array_values(array_map('strval', $raw)) : ['127.0.0.1:7000'];
    }
}
