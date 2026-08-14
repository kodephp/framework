<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Fibers\Fibers;
use Kode\Framework\Resilience\Events\TimeoutExceeded as TimeoutExceededEvent;
use Kode\Framework\Resilience\TimeoutExceeded;
use Kode\Framework\Resilience\TimeoutScheduler;
use Kode\Framework\Resilience\TimeoutScheduler\FiberTimeoutScheduler;
use Kode\Framework\Resilience\TimeoutScheduler\SyncTimeoutScheduler;

/**
 * 超时原语（操作级执行预算），与熔断、重试、幂等共同构成「稳定性四件套」：
 *
 *  - 熔断（Breaker）：下游「挂了」就别再打（故障隔离）；
 *  - 重试（Retry）：下游「抖一下」自动再试（瞬态恢复）；
 *  - 超时（Timeout）：任何依赖都不能无限拖住我（执行预算）；
 *  - 幂等（Idempotency）：重试 / 重放安全（副作用不重入）。
 *
 * 设计遵循薄壳哲学：本类只做「编排 + 降级 + 事件」；真正的计时 / 抢占由
 * {@see TimeoutScheduler} 契约的三种零依赖后端提供（fiber / pcntl / sync），
 * 事件由可注入的派发闭包（框架默认接 event()）发出。无外部依赖、运行时无关。
 *
 * 行为要点：
 *  - seconds：允许的操作秒数，默认取自构造注入（Provider 据 config/resilience.php 的 timeout 段）；
 *  - label：日志 / 事件标识，默认 'anonymous'；
 *  - scheduler：显式指定后端（'fiber'|'pcntl'|'sync'），null=自动（有 fiber 走 fiber，否则 sync）；
 *  - fallback：超时时的降级回调 fn(TimeoutExceeded): mixed；提供则超时返回其返回值；
 *  - throw：true（默认）超时抛 {@see TimeoutExceeded}；false 且未提供 fallback 则返回 null。
 *
 * 失败时抛 {@see TimeoutExceeded}（或返回 fallback），绝不静默吞错。
 */
final class Timeout
{
    /**
     * @param TimeoutScheduler|null $scheduler      显式后端（null=自动解析）
     * @param float                 $defaultSeconds 未显式传 seconds 时的默认预算
     * @param bool                  $throw          超时是否抛异常（false 且无 fallback 则返回 null）
     * @param \Closure|null         $dispatcher      事件派发闭包 fn(object $event): void
     */
    public function __construct(
        private readonly ?TimeoutScheduler $scheduler = null,
        private readonly float $defaultSeconds = 5.0,
        private readonly bool $throw = true,
        private readonly ?\Closure $dispatcher = null,
    ) {
    }

    /**
     * 在预算内执行操作；超时按 fallback / throw 处置。
     *
     * @param array<string, mixed> $options
     * @return mixed 操作成功返回值、fallback 返回值、或 null（不抛且未配 fallback）
     *
     * @throws TimeoutExceeded 超时且未提供 fallback、throw=true
     * @throws \Throwable     操作自身抛出的异常（原样透传）
     */
    public function run(callable $operation, array $options = []): mixed
    {
        $seconds = (float) ($options['seconds'] ?? $this->defaultSeconds);
        $label = (string) ($options['label'] ?? 'anonymous');
        $scheduler = $this->resolveScheduler($options['scheduler'] ?? null);

        try {
            return $scheduler->run($operation, $seconds);
        } catch (TimeoutExceeded $e) {
            $this->dispatch(new TimeoutExceededEvent($label, $seconds, $e));

            if (array_key_exists('fallback', $options) && $options['fallback'] instanceof \Closure) {
                return ($options['fallback'])($e);
            }

            if ($this->throw) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * 解析后端：显式 > 注入单例 > 自动（fiber 优先，否则 sync）。
     *
     * @param 'fiber'|'pcntl'|'sync'|null $name
     */
    private function resolveScheduler(?string $name): TimeoutScheduler
    {
        if ($name === null) {
            return $this->scheduler ?? self::autoScheduler();
        }

        return match ($name) {
            'fiber' => new FiberTimeoutScheduler(),
            'pcntl' => new PcntlTimeoutScheduler(),
            'sync' => new SyncTimeoutScheduler(),
            default => self::autoScheduler(),
        };
    }

    private static function autoScheduler(): TimeoutScheduler
    {
        return class_exists(Fibers::class) ? new FiberTimeoutScheduler() : new SyncTimeoutScheduler();
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
