<?php

declare(strict_types=1);

namespace Kode\Framework\Http\RateLimit;

use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\RedisMode;
use Kode\Limiting\Enum\LimiterType;
use Kode\Limiting\Limiter;

/**
 * 限流器工厂：把「一条 #[RateLimit] 属性规则」与「框架统一存储配置」合成为可消费的 Limiter。
 *
 * 设计立场：
 *  - **规则与存储解耦**。规则（kode/limiting 的 {@see RateLimit} 属性）描述「限制什么」
 *    （容量/速率/算法），存储描述「状态存哪」（内存 / APCu / Redis / ...）。同一套规则在
 *    单机用内存、在集群把 driver 改为 redis 即变为分布式、跨进程/跨机共享限额——无需改业务代码。
 *  - **按签名缓存**。同一条规则在多次请求间复用同一个 Limiter 实例，避免重复建连
 *    （尤其 Redis 场景，连接只建一次）。
 *
 * 限流算法与「规则对象」本身由 kode/limiting 提供，本工厂只做「框架存储配置 → kode/limiting
 * 各后端工厂方法」的桥接，不重复实现限流算法。
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

    /** 存储侧签名前缀（driver + 地址 + prefix），构造时算一次（v1.0.0 热路径优化）。 */
    private readonly string $storeSignature;

    /**
     * @param array<string, mixed> $config 框架 config/limiting.php 全量配置
     */
    public function __construct(private readonly array $config = [])
    {
        $this->storeSignature = $this->buildStoreSignature();
    }

    /**
     * 为一条 #[RateLimit] 属性规则构建（或取缓存的）限流器。
     */
    public function make(RateLimit $rule): Limiter
    {
        $signature = $this->storeSignature . sprintf(
            '|%s|%d|%s|%d',
            $rule->type->value,
            $rule->capacity,
            $rule->rate,
            $rule->tokens,
        );

        return $this->cache[$signature] ??= $this->build($rule);
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    private function build(RateLimit $rule): Limiter
    {
        $driver = (string) ($this->config['driver'] ?? 'memory');

        // memory 保留原有算法工厂映射（无存储前缀语义）。
        return match ($driver) {
            'apcu' => Limiter::create(
                $rule->type,
                ['capacity' => $rule->capacity, 'refillRate' => $rule->rate],
                $this->apcuStore(),
            ),
            'memcached' => Limiter::create(
                $rule->type,
                ['capacity' => $rule->capacity, 'refillRate' => $rule->rate],
                $this->memcachedStore(),
            ),
            'pdo' => Limiter::create(
                $rule->type,
                ['capacity' => $rule->capacity, 'refillRate' => $rule->rate],
                $this->pdoStore(),
            ),
            'redis' => Limiter::create(
                $rule->type,
                ['capacity' => $rule->capacity, 'refillRate' => $rule->rate],
                $this->redisStore(),
            ),
            default => $this->memoryLimiter($rule),
        };
    }

    /**
     * Redis store 数组（standalone / sentinel / cluster）。
     *
     * kode/limiting 2.1.0 的 storeFromArray 只支持 standalone，且 Limiter::redis() 对
     * sentinel / cluster 分支不传 prefix、standalone 硬编码前缀 → 配置 redis.prefix 在
     * HA 模式下不生效（H7）；**2.2.0 起上游三处全部修补**：storeFromArray 按 config['mode']
     * 分发 standalone/sentinel/cluster 三分支，且 prefix 全程受控。此处统一走 storeFromArray，
     * 删除 v1.0.0 的手搓 redisHA()（直接构造 {@see RedisStore} 实例）历史规避。
     *
     * 字段名对齐上游 2.2.0：sentinel 读 sentinels / masterName，cluster 读 clusterNodes；
     * 框架 config 侧为 sentinels / master_name / cluster_nodes（见 config/limiting.php）。
     *
     * @return array<string, mixed>
     */
    private function redisStore(): array
    {
        return match ($this->redisMode()) {
            RedisMode::SENTINEL => [
                'type' => 'redis',
                'mode' => 'sentinel',
                'sentinels' => $this->sentinels(),
                'masterName' => (string) ($this->config['redis']['master_name'] ?? 'mymaster'),
                'password' => isset($this->config['redis']['password'])
                    ? (string) $this->config['redis']['password'] : null,
                'database' => (int) ($this->config['redis']['database'] ?? 0),
                'prefix' => (string) ($this->config['redis']['prefix'] ?? 'kode:limiting:'),
            ],
            RedisMode::CLUSTER => [
                'type' => 'redis',
                'mode' => 'cluster',
                'clusterNodes' => $this->clusterNodes(),
                'password' => isset($this->config['redis']['password'])
                    ? (string) $this->config['redis']['password'] : null,
                'prefix' => (string) ($this->config['redis']['prefix'] ?? 'kode:limiting:'),
            ],
            default => [
                'type' => 'redis',
                'mode' => 'standalone',
                'host' => (string) ($this->config['redis']['host'] ?? '127.0.0.1'),
                'port' => (int) ($this->config['redis']['port'] ?? 6379),
                'prefix' => (string) ($this->config['redis']['prefix'] ?? 'kode:limiting:'),
                'password' => isset($this->config['redis']['password'])
                    ? (string) $this->config['redis']['password'] : null,
                'database' => (int) ($this->config['redis']['database'] ?? 0),
            ],
        };
    }

    /**
     * Memcached：经 store 数组路径，读独立 memcached 段（H6——旧实现错误地读了
     * redis.host/port，且 config 无 memcached 段时全部落到默认值，配置形同虚设）。
     *
     * @return array<string, mixed>
     */
    private function memcachedStore(): array
    {
        return [
            'type' => 'memcached',
            'host' => (string) ($this->config['memcached']['host'] ?? '127.0.0.1'),
            'port' => (int) ($this->config['memcached']['port'] ?? 11211),
            'prefix' => (string) ($this->config['memcached']['prefix'] ?? 'kode:limiting:'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apcuStore(): array
    {
        return [
            'type' => 'apcu',
            'prefix' => (string) ($this->config['redis']['prefix'] ?? 'kode:limiting:'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pdoStore(): array
    {
        return [
            'type' => 'pdo',
            'dsn' => (string) ($this->config['pdo']['dsn'] ?? 'sqlite::memory:'),
            'username' => isset($this->config['pdo']['username']) ? (string) $this->config['pdo']['username'] : null,
            'password' => isset($this->config['pdo']['password']) ? (string) $this->config['pdo']['password'] : null,
            'table' => (string) ($this->config['pdo']['table'] ?? 'limiting'),
        ];
    }

    private function memoryLimiter(RateLimit $rule): Limiter
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

    /**
     * 预计算存储侧签名（仅供构造 {@see $storeSignature}）：只依赖 config 中
     * driver/地址/prefix 等静态段，热路径每请求拼签名时免去 match + 多次兜底取值（v1.0.0）。
     * 各分支字符串与 v1.0.0 的 signature() 中 store 段逐字一致，缓存键语义不变。
     */
    private function buildStoreSignature(): string
    {
        $driver = (string) ($this->config['driver'] ?? 'memory');

        return match ($driver) {
            'redis' => 'redis:' . $this->redisMode()->value
                . ':' . ($this->config['redis']['host'] ?? '')
                . ':' . ($this->config['redis']['port'] ?? '')
                . ':' . (string) ($this->config['redis']['prefix'] ?? ''),
            'apcu' => 'apcu:' . (string) ($this->config['redis']['prefix'] ?? ''),
            'memcached' => 'memcached:'
                . ':' . ($this->config['memcached']['host'] ?? '')
                . ':' . ($this->config['memcached']['port'] ?? '')
                . ':' . (string) ($this->config['memcached']['prefix'] ?? ''),
            'pdo' => 'pdo',
            default => 'memory',
        };
    }
}
