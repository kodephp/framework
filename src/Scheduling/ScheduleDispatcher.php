<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

use Kode\Process\Kode;
use Throwable;

/**
 * 调度器：把已发现的 {@see ScheduledTask} 注册到运行时定时器并驱动事件循环。
 *
 * - 单进程/开发：Kode::cron()（每 worker 一份，参考 kode/process Crontab 注释）；
 * - 多进程/多机需「至多一次」：任务上 #[Cron(cluster: true)] 时改用
 *   Kode::cronCluster()（分布式锁，需先配置协调存储 Cluster::make(...)）。
 *
 * 注册后调用 run() 进入常驻循环：周期推进所有定时器（含 cron），并通过
 * SIGINT/SIGTERM 优雅退出（先把未决任务跑完再收尾）。
 */
final class ScheduleDispatcher
{
    /** 循环推进间隔（微秒）。cron 以分钟为粒度，250ms 足够精确且省 CPU。 */
    private const TICK_USLEEP = 250_000;

    /** 已注册的任务（含未启用的占位，便于 list 展示）。 */
    private array $registered = [];

    /** 优雅退出信号。 */
    private bool $stopping = false;

    /**
     * @param \Closure(string):object|null $resolver 解析任务类实例的回调；
     *        默认用全局 resolve()（走框架容器，支持构造/属性注入）。测试可注入闭包。
     */
    public function __construct(
        private readonly ?\Closure $resolver = null,
    ) {
    }

    /**
     * 注册任务到运行时定时器。
     *
     * @param list<ScheduledTask> $tasks
     * @return int 实际注册（启用）的任务数
     */
    public function register(array $tasks): int
    {
        $count = 0;

        foreach ($tasks as $task) {
            $this->registered[] = $task;

            if (!$task->enabled) {
                logger()->info(sprintf('[schedule] 跳过已禁用任务 %s（%s）', $task->name, $task->expression));
                continue;
            }

            $callback = function () use ($task): void {
                $this->invoke($task);
            };

            if ($task->cluster) {
                Kode::cronCluster($task->expression, $callback);
            } else {
                Kode::cron($task->expression, $callback);
            }

            $count++;
        }

        return $count;
    }

    /**
     * 进入常驻循环，直到收到退出信号。
     */
    public function run(): void
    {
        $this->installSignalHandlers();

        logger()->info(sprintf('[schedule] 调度器启动，共 %d 条任务，按 Ctrl+C 停止', count($this->registered)));

        while (!$this->stopping) {
            // 推进所有定时器与 cron 任务（kode/process Timer::tick）。
            Kode::tickTimers();

            // 处理信号（无需 declare(ticks=1)，在循环里显式派发）。
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(self::TICK_USLEEP);
        }

        logger()->info('[schedule] 调度器已停止');
    }

    /**
     * 已注册任务（用于 schedule:list）。
     *
     * @return list<ScheduledTask>
     */
    public function registered(): array
    {
        return $this->registered;
    }

    /**
     * 立即手动触发一条任务一次（不依赖 cron 调度）。
     *
     * 用于：调试时手动跑某条任务、或 CI 里显式触发。返回是否找到并执行。
     */
    public function runOnce(string $name): bool
    {
        foreach ($this->registered as $task) {
            if ($task->name === $name) {
                $this->invoke($task);

                return true;
            }
        }

        return false;
    }

    /**
     * 调用任务方法（走容器，与路由 handler 同约定：支持构造注入/属性注入）。
     */
    private function invoke(ScheduledTask $task): void
    {
        $started = microtime(true);
        try {
            $instance = $this->resolver !== null
                ? ($this->resolver)($task->class)
                : resolve($task->class);
            $instance->{$task->method}();
            $ms = round((microtime(true) - $started) * 1000, 2);
            logger()->info(sprintf('[schedule] ✓ %s（%s）耗时 %sms', $task->name, $task->expression, $ms));
        } catch (Throwable $e) {
            logger()->error(
                sprintf('[schedule] ✗ %s 执行失败：%s', $task->name, $e->getMessage()),
                ['exception' => $e]
            );
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $sig): void {
            logger()->info(sprintf('[schedule] 收到信号 %d，准备优雅退出', $sig));
            $this->stopping = true;
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
