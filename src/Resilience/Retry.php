<?php

declare(strict_types=1);

namespace Kode\Framework\Resilience;

use Kode\Framework\Resilience\Backoff\BackoffStrategy;
use Kode\Framework\Resilience\Events\RetryAttempting;
use Kode\Framework\Resilience\Events\RetryExhausted as RetryExhaustedEvent;
use Kode\Framework\Resilience\Events\RetrySucceeded;

/**
 * 重试原语（瞬态故障恢复），与熔断器互补：
 *
 *  - 熔断（Breaker）保护下游不因故障级联雪崩（下游「挂了」就别再打）；
 *  - 重试（Retry）在下游「抖了一下」时自动再试，把瞬态错误对调用方屏蔽掉。
 *
 * 设计遵循薄壳哲学：本类只做「重试编排」——尝试次数、退避策略、可重试判定、总预算、
 * 事件派发；退避算法由 {@see BackoffStrategy} 契约（内置三种零依赖实现）提供，
 * 事件由可注入的派发闭包（框架默认接 event()）发出。无外部依赖、运行时无关。
 *
 * 行为要点：
 *  - attempts：最大尝试次数（含首次），默认 3；
 *  - backoff：{@see BackoffStrategy}（或 null = 不等待）；未指定时取构造注入的默认退避；
 *  - retryOn：可重试判定——null=任何异常都重试；callable(Throwable):bool=自定义；
 *    list<class-string>=仅这些异常类型重试；
 *  - timeout：总预算（秒），单次退避若会使总耗时超预算则停止重试（避免长尾）；
 *  - label：日志 / 事件标识，默认 'anonymous'。
 *
 * 失败时抛 {@see RetryExhausted}（携带实际尝试次数 + 历次失败），绝不静默吞错。
 */
final class Retry
{
    /**
     * @param BackoffStrategy|null $defaultBackoff 未显式传 backoff 时使用的默认退避（Provider 据配置注入）
     * @param \Closure|null        $dispatcher     事件派发闭包 fn(object $event): void
     * @param \Closure|null        $sleeper        等待闭包 fn(float $seconds): void（默认 usleep；测试可注入）
     */
    public function __construct(
        private readonly ?BackoffStrategy $defaultBackoff = null,
        private readonly ?\Closure $dispatcher = null,
        private readonly ?\Closure $sleeper = null,
    ) {
    }

    /**
     * 执行操作，遇可重试异常按策略重试；全部失败抛 {@see RetryExhausted}。
     *
     * @param array<string, mixed> $options
     * @return mixed 操作成功返回值
     */
    public function run(callable $operation, array $options = []): mixed
    {
        $attempts = max(1, (int) ($options['attempts'] ?? 3));
        $backoff = $options['backoff'] ?? $this->defaultBackoff;
        $retryOn = $options['retryOn'] ?? null;
        $timeout = $options['timeout'] ?? null;
        $label = (string) ($options['label'] ?? 'anonymous');
        $sleeper = $options['sleep'] ?? $this->sleeper;
        if (!$sleeper instanceof \Closure) {
            $sleeper = static function (float $seconds): void {
                usleep((int) ($seconds * 1_000_000));
            };
        }

        $failures = [];
        $deadline = $timeout !== null ? microtime(true) + $timeout : null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $result = $operation();

                // 首次成功不派发（无重试）；第 2 次及以后成功 = 重试恢复。
                if ($i > 1) {
                    $this->dispatch(new RetrySucceeded($label, $i));
                }

                return $result;
            } catch (\Throwable $e) {
                $failures[] = $e;

                // 已达最大尝试 / 不可重试 / 退避将超总预算 → 停止重试。
                if ($i >= $attempts
                    || !$this->shouldRetry($retryOn, $e)
                    || ($deadline !== null && $this->wouldExceedBudget($backoff, $i, $deadline))
                ) {
                    break;
                }

                $delay = $backoff instanceof BackoffStrategy ? $backoff->delay($i) : 0.0;
                if ($delay > 0.0) {
                    $this->dispatch(new RetryAttempting($label, $i, $delay));
                    $sleeper($delay);
                }
            }
        }

        $ex = new RetryExhausted(count($failures), $failures, previous: $failures !== [] ? end($failures) : null);
        $this->dispatch(new RetryExhaustedEvent($label, $ex->attempts, $ex->last() ?? $ex));

        throw $ex;
    }

    /**
     * 是否应重试：null=任何异常都重试；callable=自定义布尔；array=仅列出的异常类型重试。
     *
     * @param callable(\Throwable):bool|list<class-string<\Throwable>>|null $retryOn
     */
    private function shouldRetry(mixed $retryOn, \Throwable $e): bool
    {
        if ($retryOn === null) {
            return true;
        }
        if (is_callable($retryOn)) {
            return (bool) $retryOn($e);
        }
        if (is_array($retryOn)) {
            foreach ($retryOn as $class) {
                if ($e instanceof $class) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * 若本次退避后总耗时将超预算，则不再重试（避免长尾 + 预算外阻塞）。
     */
    private function wouldExceedBudget(?BackoffStrategy $backoff, int $attempt, float $deadline): bool
    {
        $delay = $backoff instanceof BackoffStrategy ? $backoff->delay($attempt) : 0.0;

        return (microtime(true) + $delay) > $deadline;
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
