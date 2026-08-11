<?php

declare(strict_types=1);

namespace Kode\Framework\Http\RateLimit;

use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Limiter;

/**
 * 限流器工厂：把「一条限流规则」与「框架统一存储配置」合成为可消费的 Limiter。
 *
 * 设计立场：
 *  - **规则与存储解耦**。规则描述「限制什么」（容量/速率/算法），存储描述「状态存哪」
 *    （内存 / APCu / Redis / ...）。同一套规则在单机用内存、在集群把 driver 改为 redis
 *    即变为分布式、跨进程/跨机共享限额——无需改业务代码。
 *  - **按签名缓存**。同一条规则在多次请求间复用同一个 Limiter 实例，避免重复建连
 *    （尤其 Redis 场景，连接只建一次）。
 *
 * 支持 kode/limiting 的全部后端：memory / apcu / redis（standalone·sentinel·cluster）
 * / memcached / pdo。
 */
final class LimiterFactory
{
    /**
     * 已构建的 Limiter 缓存（签名 => Limiter）。
     *
     * @var array<string, Limiter>
     */
    private array $cache = [];

    /**
     * @param array<string, mixed> $config 框架 config/limiting.php 全量配置
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * 为一条规则构建（或取缓存的）限流器。
     */
    public function make(RateLimitRule $rule): Limiter
    {
        $signature = $this->signature($rule);

        return $this->cache[$signature] ??= $this->build($rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    private function build(RateLimitRule $rule): Limiter
    {
        $driver = (string) ($this->config['driver'] ?? 'memory');

        return match ($driver) {
            'apcu' => Limiter::apcu($rule->type, $rule->capacity, $rule->rate),
            'memcached' => Limiter::memcached(
                $rule->type,
                $rule->capacity,
                $rule->rate,
                (string) ($this->config['redis']['host'] ?? '127.0.0.1'),
                (int) ($this->config['redis']['port'] ?? 11211),
            ),
            'pdo' => Limiter::pdo(
                $rule->type,
                $rule->capacity,
                $rule->rate,
                (string) ($this->config['pdo']['dsn'] ?? 'sqlite::memory:'),
                isset($this->config['pdo']['username']) ? (string) $this->config['pdo']['username'] : null,
                isset($this->config['pdo']['password']) ? (string) $this->config['pdo']['password'] : null,
                (string) ($this->config['pdo']['table'] ?? 'limiting'),
            ),
            'redis' => Limiter::redis(
                $rule->type,
                $rule->capacity,
                $rule->rate,
                (string) ($this->config['redis']['host'] ?? '127.0.0.1'),
                (int) ($this->config['redis']['port'] ?? 6379),
                $this->config['redis']['password'] ?? null,
                (int) ($this->config['redis']['database'] ?? 0),
                $this->redisMode(),
                $this->sentinels(),
                (string) ($this->config['redis']['master_name'] ?? 'mymaster'),
                $this->clusterNodes(),
            ),
            default => $this->memoryLimiter($rule),
        };
    }

    private function memoryLimiter(RateLimitRule $rule): Limiter
    {
        return match ($rule->type) {
            LimiterType::SLIDING_WINDOW => Limiter::slidingWindow($rule->capacity, $rule->rate),
            LimiterType::SLIDING_WINDOW_COUNTER => Limiter::slidingWindowCounter($rule->capacity, (int) $rule->rate),
            LimiterType::COUNTER => Limiter::counter($rule->capacity, (int) $rule->rate),
            LimiterType::LEAKY_BUCKET => Limiter::leakyBucket($rule->capacity, $rule->rate),
            default => Limiter::tokenBucket($rule->capacity, $rule->rate),
        };
    }

    private function redisMode(): RedisMode
    {
        return match ((string) ($this->config['redis']['mode'] ?? 'standalone')) {
            'sentinel' => RedisMode::SENTINEL,
            'cluster' => RedisMode::CLUSTER,
            default => RedisMode::STANDALONE,
        };
    }

    /**
     * @return list<string>
     */
    private function sentinels(): array
    {
        $raw = $this->config['redis']['sentinels'] ?? ['127.0.0.1:26379'];

        return is_array($raw) ? array_values(array_map('strval', $raw)) : ['127.0.0.1:26379'];
    }

    /**
     * @return list<string>
     */
    private function clusterNodes(): array
    {
        $raw = $this->config['redis']['cluster_nodes'] ?? ['127.0.0.1:7000'];

        return is_array($raw) ? array_values(array_map('strval', $raw)) : ['127.0.0.1:7000'];
    }

    private function signature(RateLimitRule $rule): string
    {
        $store = match ((string) ($this->config['driver'] ?? 'memory')) {
            'redis' => 'redis:' . $this->redisMode()->value
                . ':' . ($this->config['redis']['host'] ?? '')
                . ':' . ($this->config['redis']['port'] ?? ''),
            'apcu' => 'apcu',
            'memcached' => 'memcached',
            'pdo' => 'pdo',
            default => 'memory',
        };

        return sprintf(
            '%s|%s|%d|%s|%d',
            $store,
            $rule->type->value,
            $rule->capacity,
            $rule->rate,
            $rule->tokens,
        );
    }
}
