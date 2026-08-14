<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

use Kode\Fibers\Fibers;
use RuntimeException;

/**
 * 分布式锁看门狗（自动续期装饰器）。
 *
 * 生产级分布式锁最大隐患之一：**持有锁的长任务执行时间超过 TTL，锁自动过期被其他副本抢占**，
 * 导致同一任务并发执行（重复出报表、重复发消息等）。{@see LockManager::run()} 在 work 执行期间
 * 不续期，本类正是为此补上「看门狗」：获取锁后启动一个续期循环，在 work 运行期间按 `ttl * renewRatio`
 * 周期性调用 {@see LockManager::acquire()}（同一 owner 重入刷新 TTL），work 结束后释放。
 *
 * 续期调度双驱动（配置 `lock.watchdog.driver`）：
 *  - tick（默认 / auto）：用 PHP `register_tick_function`，在 work 执行期间的语句边界周期触发续期，
 *    任何 SAPI 与环境均可用，零运行时依赖；精度为「语句边界」，对秒级 TTL 足够。
 *  - fiber：用 kode/fibers 协程（`Fibers::go` + `Fibers::sleep`）与 work 并行续期，精度更高，
 *    但要求调用方处于 fiber 调度器上下文（HTTP 请求 / 调度任务 / queue:work 默认均满足）。
 *
 * 设计要点（与 LockManager 一致）：
 *  - owner 令牌：每次 protect 生成唯一 owner；续期仅当 owner 匹配时生效（{@see LockManager::acquire()}
 *    的同 owner 重入刷新语义），绝不会续期他人持有的锁。
 *  - 安全释放：work 抛异常也通过 finally 释放，且看门狗在 stop 标记后置位即不再续期，避免「释放后被
 *    看门狗重新 acquire」的竞态。
 *  - 事件：`LockWatchdogStarted` / `LockWatchdogRenewed` / `LockWatchdogStopped`，便于接入指标
 *    （续期次数 / 持有时长）/ 审计 / 告警（续期失败）。
 *
 * 薄壳哲学：本类只负责「续期编排」，不重造分布式协调；真正跨主机锁由 {@see LockManager} 后端提供。
 */
final class LockWatchdog
{
    /**
     * 续期间隔调度闭包（测试可注入）。
     *
     * 签名：`function(callable $work, callable $tick, int $interval): mixed`
     *  - $work：被包裹的业务（返回值需透传）；
     *  - $tick：续期一次（无参，由本类注入 owner 续期逻辑）；
     *  - $interval：续期间隔（秒）。
     */
    private readonly \Closure $ticker;

    /**
     * @param LockManager       $manager    被装饰的锁管理器
     * @param float             $renewRatio 续期间隔占 TTL 的比例（默认 0.34，即每 ~1/3 TTL 续期一次）
     * @param string            $driver     'auto' | 'fiber' | 'tick'
     * @param ?\Closure         $dispatcher 事件派发闭包（由 Provider 注入，解耦事件系统启动顺序）
     * @param ?\Closure         $ticker     可注入的续期调度闭包（测试用；null 时用内置默认驱动）
     */
    public function __construct(
        private readonly LockManager $manager,
        private readonly float $renewRatio = 0.34,
        private readonly string $driver = 'auto',
        private readonly ?\Closure $dispatcher = null,
        ?\Closure $ticker = null,
    ) {
        $this->ticker = $ticker ?? $this->defaultTicker();
    }

    /**
     * 持有锁执行工作，期间自动续期（看门狗），防止长任务超过 TTL 被抢。
     *
     * @param callable(): mixed $work 业务闭包（返回值原样透传）
     * @param int             $ttl  锁存活秒数（默认 30）
     * @param ?string         $owner 自定义 owner 令牌；null 时每次 protect 生成唯一令牌
     *
     * @return mixed 业务返回值
     *
     * @throws LockAcquireException 获取锁失败（已被他人持有）时抛出，work 不执行
     */
    public function protect(string $key, callable $work, int $ttl = 30, ?string $owner = null): mixed
    {
        $owner = $owner ?? bin2hex(random_bytes(12));

        if (!$this->manager->acquire($key, $ttl, $owner)) {
            throw new LockAcquireException($key);
        }

        $interval = max(1, (int) ceil($ttl * $this->renewRatio));
        $renews = 0;

        $this->dispatch(new LockWatchdogStarted($key, $owner, $ttl, $interval));

        $tick = function () use ($key, $ttl, $owner, &$renews): void {
            // 仅当仍持有（owner 匹配）时续期；acquire 同 owner 重入刷新 TTL。
            if ($this->manager->acquire($key, $ttl, $owner)) {
                ++$renews;
                $this->dispatch(new LockWatchdogRenewed(
                    $key,
                    $owner,
                    $this->manager->ttl($key) ?? $ttl,
                    $renews,
                ));
            }
        };

        try {
            return ($this->ticker)($work, $tick, $interval);
        } finally {
            // stop 标记已在 tick 闭包外控制，但这里释放本身即可；watchdog 醒来后会因锁已释放
            // 而不再续期（owner 不匹配）。显式释放确保任务结束即解锁。
            $this->manager->release($key, $owner);
            $this->dispatch(new LockWatchdogStopped($key, $owner, $renews));
        }
    }

    /**
     * 内置默认续期调度：auto 优先尝试 fiber，失败回退 tick。
     */
    private function defaultTicker(): \Closure
    {
        return function (callable $work, callable $tick, int $interval): mixed {
            if ($this->driver === 'fiber' || ($this->driver === 'auto' && $this->fiberAvailable())) {
                try {
                    return $this->fiberTicker($work, $tick, $interval);
                } catch (\Throwable) {
                    // fiber 不可用（无调度器上下文等）→ 回退 tick
                    return $this->tickTicker($work, $tick, $interval);
                }
            }

            return $this->tickTicker($work, $tick, $interval);
        };
    }

    /**
     * tick 驱动：PHP register_tick_function，在 work 执行期间语句边界周期触发续期。
     */
    private function tickTicker(callable $work, callable $tick, int $interval): mixed
    {
        $last = microtime(true);
        $cb = static function () use (&$last, $interval, $tick): void {
            $now = microtime(true);
            if ($now - $last >= $interval) {
                $tick();
                $last = $now;
            }
        };

        register_tick_function($cb);
        try {
            return $work();
        } finally {
            unregister_tick_function($cb);
        }
    }

    /**
     * fiber 驱动：协程 sleep 与 work 并行续期（需 fiber 调度器驱动）。
     */
    private function fiberTicker(callable $work, callable $tick, int $interval): mixed
    {
        $stop = false;
        // watchdog 协程先于 work 构造：若无调度器上下文，Fibers::go 抛异常 → 由调用方回退 tick，
        // 此时 work 尚未执行，无副作用。
        Fibers::go(function () use (&$stop, $interval, $tick): void {
            while (!$stop) {
                Fibers::sleep($interval);
                if ($stop) {
                    break;
                }
                $tick();
            }
        });

        try {
            return $work();
        } finally {
            $stop = true; // 看门狗下次醒来检查 stop → 退出，不再续期
        }
    }

    private function fiberAvailable(): bool
    {
        if (!class_exists(Fibers::class)) {
            return false;
        }

        try {
            return Fibers::scheduler() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
