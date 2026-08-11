<?php

declare(strict_types=1);

namespace Kode\Framework\Http\RateLimit;

use Kode\Limiting\Attribute\RateLimit;
use Kode\Limiting\Enum\LimiterType;

/**
 * 一条限流规则（从 #[RateLimit] 属性解析而来，或全局默认配置）。
 *
 * 与 kode/limiting 的 {@see RateLimit} 属性一一对应：携带容量、速率、算法、
 * 限流键模板与单次消耗额度。中间件据此向 {@see LimiterFactory} 申请对应的限流器。
 *
 * 设计立场：规则与「存储后端」解耦——存储（memory / apcu / redis / ...）由框架
 * 统一配置决定，因此同一条规则在单机用内存、在集群可无缝切到 Redis（分布式）。
 */
final class RateLimitRule
{
    /**
     * @param int         $capacity 额度上限
     * @param float       $rate     令牌补充速率（个/秒）或窗口秒数，取决于 $type
     * @param string|null $key      限流键模板，支持 {占位符}，null 表示由中间件推导
     * @param LimiterType $type     限流算法
     * @param int         $tokens   单次请求消耗额度
     * @param string      $group    规则分组名（同一入口可叠加多条规则）
     */
    public function __construct(
        public readonly int $capacity,
        public readonly float $rate,
        public readonly ?string $key,
        public readonly LimiterType $type,
        public readonly int $tokens = 1,
        public readonly string $group = 'default',
    ) {
    }

    /**
     * 由 kode/limiting 的 #[RateLimit] 属性实例构造规则。
     */
    public static function fromAttribute(RateLimit $attribute): self
    {
        return new self(
            capacity: $attribute->capacity,
            rate: $attribute->rate,
            key: $attribute->key,
            type: $attribute->type,
            tokens: $attribute->tokens,
            group: $attribute->group,
        );
    }

    /**
     * 由全局默认配置构造规则（无 key 模板，由中间件按「路由 + 客户端 IP」推导）。
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            capacity: (int) ($config['capacity'] ?? 10),
            rate: (float) ($config['rate'] ?? 1.0),
            key: null,
            type: self::typeFromName((string) ($config['algorithm'] ?? 'token_bucket')),
        );
    }

    /**
     * 用运行期上下文渲染 key 模板（{user_id} 等占位符 → 实际值）。
     *
     * @param array<string, string|int> $context
     */
    public function resolveKey(array $context, string $fallback): string
    {
        $template = $this->key ?? $fallback;

        foreach ($context as $name => $value) {
            $template = str_replace('{' . $name . '}', (string) $value, $template);
        }

        return $template;
    }

    public static function typeFromName(string $name): LimiterType
    {
        return match ($name) {
            'sliding_window' => LimiterType::SLIDING_WINDOW,
            'sliding_window_counter' => LimiterType::SLIDING_WINDOW_COUNTER,
            'counter' => LimiterType::COUNTER,
            'leaky_bucket' => LimiterType::LEAKY_BUCKET,
            default => LimiterType::TOKEN_BUCKET,
        };
    }
}
