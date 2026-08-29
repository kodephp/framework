<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

use Kode\Fibers\Concurrency\Scheduler;
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
 *    零运行时依赖；精度为「语句边界」，对秒级 TTL 足够。注意：ticks 仅在以 declare(ticks=1)
 *    声明的业务文件内触发，未声明时该驱动不生效（运行期会检测并告警）。
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
     * 内置默认续期调度：auto 优先尝试 fiber（仅在真正处于调度器上下文中），否则回退 tick。
     *
     * 修复说明（v1.0.42）：
     *  - 旧实现用 `Fibers::scheduler() !== null` 判断 fiber 可用性，但 kode/fibers 的
     *    `Scheduler::default()` 总会创建并返回默认调度器、永不为 null，导致 auto 模式恒走
     *    fiber 分支；
     *  - 更致命的是 `Fibers::go()` 的语义是「同步执行任务直至完成」（协程内直接 `$callable()`，
     *    协程外 new Scheduler + join），并非「后台并行启动」。旧 `fiberTicker()` 用它挂载
     *    watchdog 循环（`$stop` 初始为 false），该循环永不结束，`$work()` 永远不会执行——
     *    业务被无限阻塞，等价于无看门狗 + 锁被长期占用。
     *  - 现在：仅当 `Scheduler::inCoroutine()` 为真（当前执行单元确处于事件循环内）才用
     *    fiber 驱动，且改用 `Scheduler::current()->go()` 把续期协程并行挂到当前调度器
     *    （非阻塞），由调度器在 `$work()` 让出执行权时推进续期；`$work()` 自身异常不再被
     *    吞掉并重跑 tick（那正是「业务双执行」的成因之一），原样向上传播。
     */
    private function defaultTicker(): \Closure
    {
        return function (callable $work, callable $tick, int $interval): mixed {
            if ($this->driver === 'fiber' || ($this->driver === 'auto' && $this->fiberAvailable())) {
                return $this->fiberTicker($work, $tick, $interval);
            }

            return $this->tickTicker($work, $tick, $interval);
        };
    }

    /**
     * tick 驱动：PHP register_tick_function，在 work 执行期间语句边界周期触发续期。
     *
     * 重要限制：ticks 只在以 declare(ticks=N) 编译的作用域内触发。若业务代码文件
     * 未声明 ticks，续期回调一次都不会执行——本实现会在 work 结束后检测「长任务
     * 期间 0 次续期」并告警（v1.0.52），避免该静默失效伪装成可用防线。
     */
    private function tickTicker(callable $work, callable $tick, int $interval): mixed
    {
        $last = microtime(true);
        $started = $last;
        $renewals = 0;
        $cb = static function () use (&$last, &$renewals, $tick, $interval): void {
            $now = microtime(true);
            if ($now - $last >= $interval) {
                $tick();
                ++$renewals;
                $last = $now;
            }
        };

        register_tick_function($cb);
        try {
            return $work();
        } finally {
            unregister_tick_function($cb);
            // work 耗时已超过一个续期周期却一次续期都没发生 ⇒ tick 驱动未生效
            // （业务作用域无 declare(ticks=1)），锁将在 TTL 后被他人抢占。
            if ($renewals === 0 && microtime(true) - $started > $interval) {
                error_log(
                    '[LockWatchdog] tick 驱动未产生任何续期：业务代码所在文件需声明 declare(ticks=1)，'
                    . '或改用协程环境下的 fiber 驱动，否则长任务超时后锁会被抢占。'
                );
            }
        }
    }

    /**
     * fiber 驱动：把续期协程**并行挂载**到当前事件循环，与 work 并行推进（需 fiber 调度器驱动）。
     *
     * 与旧实现的差异（v1.0.42 修复）：
     *  - 不再使用 `Fibers::go()`（同步执行直到任务结束，会把 watchdog 无限循环阻塞在当前
     *    执行单元上，导致 `$work()` 永远不执行）；改用 `Scheduler::current()->go()` 把协程
     *    挂到当前调度器后就立即返回（非阻塞），`$work()` 在自身协程继续执行；
     *  - watchdog 协程内部捕获一切异常：续期失败不应打断业务主流程（与 tick 驱动语义一致），
     *    看门狗静默退出即可，业务按「无看门狗」路径继续。
     */
    private function fiberTicker(callable $work, callable $tick, int $interval): mixed
    {
        // fiberAvailable() 已保证当前在协程内（inCoroutine() 为真），current() 必非 null；
        // 仍加防御：若当前实现变化导致走到此处时无调度器，则回退 tick 而非静默死锁。
        $scheduler = Scheduler::current();
        if ($scheduler === null) {
            return $this->tickTicker($work, $tick, $interval);
        }

        $stop = false;
        $scheduler->go(static function () use (&$stop, $interval, $tick): void {
            try {
                while (!$stop) {
                    Fibers::sleep($interval);
                    if ($stop) {
                        break;
                    }
                    $tick();
                }
            } catch (\Throwable) {
                // 续期失败绝不中断业务：看门狗静默退出，work 继续执行。
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

        // 必须判断「当前执行单元是否处于事件循环内」：Scheduler::default() 总是非 null，
        // 用它判断会把非协程上下文误判为可用（旧 bug 根因一）。
        try {
            return Scheduler::inCoroutine();
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
