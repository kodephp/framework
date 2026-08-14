<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Resilience\Backoff\BackoffStrategy;
use Kode\Framework\Resilience\Backoff\DecorrelatedJitterBackoff;
use Kode\Framework\Resilience\Backoff\ExponentialBackoff;
use Kode\Framework\Resilience\Backoff\FixedBackoff;
use Kode\Framework\Resilience\Breaker;
use Kode\Framework\Resilience\CircuitBreaker;
use Kode\Framework\Resilience\FiberBreaker;
use Kode\Framework\Resilience\Retry;
use Kode\Framework\Resilience\Timeout;
use Kode\Fibers\Core\CircuitBreaker as FiberCircuitBreaker;

/**
 * 韧性（熔断器）服务提供者。
 *
 * 依据 config/resilience.php 构建 Breaker 单例并绑定到容器，
 * 门面 Breaker / 助手 breaker() 复用它。
 *
 * 引擎由「可注入工厂」提供：默认工厂构造 FiberBreaker，底层算法由
 * kode/fibers 的 CircuitBreaker 提供（与协程、多运行时通用）。
 * 框架的 Breaker 仅依赖中性 CircuitBreaker 契约，因此引擎可替换，业务代码无感。
 *
 * 限流由 LimitingServiceProvider 提供，二者同属「稳定性」层但职责分离：
 *  - 限流：保护自身不被流量打垮（节流，429）；
 *  - 熔断：保护下游依赖不因故障级联雪崩（故障隔离 + 降级）。
 */
final class ResilienceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Breaker::class, function (): Breaker {
            $config = (array) $this->config('resilience', []);

            // 默认引擎工厂：FiberBreaker 薄适配，算法委托 kode/fibers CircuitBreaker。
            // 支持按服务名在 config/resilience.php 的 breakers.<name> 覆盖阈值。
            $factory = static function (string $name, array $cfg) use ($config): CircuitBreaker {
                /** @var array<string, mixed> $per */
                $per = $config['breakers'][$name] ?? [];
                $merged = array_merge($config, $per);

                return new FiberBreaker(new FiberCircuitBreaker(
                    failureThreshold: (int) ($merged['failure_threshold'] ?? 5),
                    recoveryTimeout: (float) ($merged['recovery_timeout'] ?? 30.0),
                    halfOpenMaxCalls: (int) ($merged['half_open_max_calls'] ?? 1),
                ));
            };

            return new Breaker($config, $factory);
        });

        $this->container->alias('breaker', Breaker::class);
        $this->container->alias('resilience', Breaker::class);

        // 重试原语（瞬态故障恢复）：默认退避来自 config/resilience.php 的 retry 段，
        // 事件经框架事件系统派发。构造零依赖、运行时无关。
        $this->container->singleton(Retry::class, function (): Retry {
            $config = (array) $this->config('resilience', []);
            $retryCfg = (array) ($config['retry'] ?? []);
            $dispatcher = static fn (object $event): object => event($event);

            return new Retry($this->makeBackoff($retryCfg), $dispatcher);
        });
        $this->container->alias('retry', Retry::class);

        // 超时原语（操作级执行预算，稳定性四件套之一）：默认秒数来自 config/resilience.php 的
        // timeout 段，事件经框架事件系统派发（TimeoutExceeded）。底层抢占由 active runtime
        // （kode/fibers）提供——对协作式挂起任务真实生效；无 fiber 时自动退化 sync。
        $this->container->singleton(Timeout::class, function (): Timeout {
            $config = (array) $this->config('resilience', []);
            $timeoutCfg = (array) ($config['timeout'] ?? []);
            $dispatcher = static fn (object $event): object => event($event);

            return new Timeout(
                scheduler: null,
                defaultSeconds: (float) ($timeoutCfg['seconds'] ?? 5.0),
                throw: (bool) ($timeoutCfg['throw'] ?? true),
                dispatcher: $dispatcher,
            );
        });
        $this->container->alias('timeout', Timeout::class);
    }

    /**
     * 据配置构造默认退避策略（fixed | exponential | decorrelated）。
     *
     * @param array<string, mixed> $cfg
     */
    private function makeBackoff(array $cfg): ?BackoffStrategy
    {
        $type = (string) ($cfg['backoff'] ?? 'exponential');

        return match ($type) {
            'fixed' => new FixedBackoff((float) ($cfg['base'] ?? 0.1)),
            'exponential' => new ExponentialBackoff(
                base: (float) ($cfg['base'] ?? 0.1),
                cap: (float) ($cfg['cap'] ?? 10.0),
                jitter: (bool) ($cfg['jitter'] ?? true),
            ),
            'decorrelated' => new DecorrelatedJitterBackoff(
                base: (float) ($cfg['base'] ?? 0.1),
                cap: (float) ($cfg['cap'] ?? 10.0),
            ),
            default => null,
        };
    }
}
