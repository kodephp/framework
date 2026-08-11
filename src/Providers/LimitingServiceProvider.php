<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Limiting\Limiter;
use Kode\Limiting\RateLimiterInterface;

/**
 * 限流服务提供者（kode/limiting，PHP 8.3+）
 *
 * 依据 config/limiting.php 构建默认 Limiter 单例并绑定到容器，
 * 门面 RateLimit / 助手 rateLimit() 与 RateLimitMiddleware 均复用它。
 * 支持 memory / apcu / redis / memcached / pdo 多种存储后端。
 */
final class LimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Limiter::class, function (): Limiter {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('limiting', []);
            $driver = (string) ($config['driver'] ?? 'memory');
            $algo = (string) ($config['algorithm'] ?? 'token_bucket');
            $capacity = (int) ($config['capacity'] ?? 10);
            $rate = (float) ($config['rate'] ?? 1.0);

            $store = match ($driver) {
                'redis' => ['type' => 'redis', ...(array) ($config['redis'] ?? [])],
                'apcu' => 'apcu',
                'memcached' => ['type' => 'memcached', ...(array) ($config['redis'] ?? [])],
                'pdo' => ['type' => 'pdo'],
                default => null, // memory
            };

            return match ($algo) {
                'sliding_window' => Limiter::slidingWindow($capacity, $rate, $store),
                'counter' => Limiter::counter($capacity, (int) $rate, $store),
                'leaky_bucket' => Limiter::leakyBucket($capacity, $rate, $store),
                'sliding_window_counter' => Limiter::slidingWindowCounter($capacity, (int) $rate, $store),
                default => Limiter::tokenBucket($capacity, $rate, $store),
            };
        });

        $this->container->alias(RateLimiterInterface::class, Limiter::class);
        $this->container->alias('rate_limit', Limiter::class);
    }
}
