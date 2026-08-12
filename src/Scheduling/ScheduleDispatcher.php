<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

use Kode\Scheduling\Coordinator\LocalCoordinator;
use Kode\Scheduling\Contract\CoordinatorInterface;
use Kode\Scheduling\RunReport;
use Kode\Scheduling\Scheduler;
use Throwable;

/**
 * 调度器：把已发现的 {@see ScheduledTask} 注册进 kode/scheduling 的 {@see Scheduler}，
 * 并由其驱动执行（cron 匹配 / 防重叠互斥 / 集群协调 / keepAlive 守护循环均由包负责）。
 *
 * 框架只保留两块「薄壳价值」：
 *  1. {@see TaskScanner} 约定优于配置地自动发现 #[Cron] 属性（类级 + 方法级，含禁用）；
 *  2. 把框架 ScheduledTask 映射到 kode/scheduling Task，并把 container resolve 注入接好。
 *
 * 运行时模型（与 kode/process 的 Kode::cron() 常驻定时器是两套实现，本类选用 kode/scheduling）：
 *  - run()        单轮：执行当前时刻到期的任务（通常由系统 crontab 每分钟触发，或测试里手动调）；
 *  - daemon()     常驻：以 keepAlive 守护循环持续调度，直到 SIGINT/SIGTERM 优雅退出；
 *  - runOnce(name) 手动触发某条任务一次（不依赖 cron 表达式，绕过协调器与 enabled）。
 *
 * 集群「至多一次」：任务上 #[Cron(cluster: true)] 时，若已配置 schedule.cluster.store，
 * 则挂上 {@see ClusterCoordinator}（委托 kode/process 分布式锁做派发裁决）；未配置则回退
 * LocalCoordinator（恒派发，单进程/单机安全，多机重复——与 kode/process ClusterCron 降级理念一致）。
 */
final class ScheduleDispatcher
{
    /** 已注册的任务（含未启用的占位，便于 list 展示）。 */
    private array $registered = [];

    /** 底层执行引擎（kode/scheduling）。 */
    private Scheduler $scheduler;

    /**
     * @param \Closure(string):object|null $resolver 解析任务类实例的回调；
     *        默认用全局 resolve()（走框架容器，支持构造/属性注入）。测试可注入闭包。
     */
    public function __construct(
        private readonly ?\Closure $resolver = null,
        ?Scheduler $scheduler = null,
    ) {
        $this->scheduler = $scheduler ?? new Scheduler();
    }

    /**
     * 把任务注册进底层 Scheduler。
     *
     * @param list<ScheduledTask> $tasks
     * @return int 实际启用（会被调度）的任务数
     */
    public function register(array $tasks): int
    {
        $count = 0;
        $useCluster = false;

        foreach ($tasks as $task) {
            $this->registered[] = $task;

            $callback = function () use ($task): void {
                $this->invoke($task);
            };

            $this->scheduler->call($task->name, $callback)
                ->cron($task->expression)
                ->enabled($task->enabled)
                ->description((string) ($task->description ?? ''));

            if ($task->enabled) {
                $count++;
            }
            if ($task->cluster) {
                $useCluster = true;
            }
        }

        if ($useCluster) {
            $this->scheduler->setCoordinator($this->coordinator());
        }

        return $count;
    }

    /**
     * 单轮执行：运行当前时刻到期的任务。
     */
    public function run(?\DateTimeImmutable $now = null): RunReport
    {
        return $this->scheduler->run($now);
    }

    /**
     * 常驻守护循环：持续调度，直到收到 SIGINT/SIGTERM。
     *
     * @param int $interval 轮询间隔（秒）；存在秒级任务时 kode/scheduling 自动降为每秒一次。
     */
    public function daemon(int $interval = 60): void
    {
        $this->scheduler->keepAlive($interval);
    }

    /**
     * 底层 Scheduler（供 schedule:list / 测试 introspect）。
     */
    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    /**
     * 已注册任务（含禁用，供 schedule:list）。
     *
     * @return list<ScheduledTask>
     */
    public function registered(): array
    {
        return $this->registered;
    }

    /**
     * 立即手动触发一条任务一次（不依赖 cron 调度，绕过协调器与 enabled）。
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

    /**
     * 选择协调器：配置了集群存储才用 ClusterCoordinator，否则本地恒派发。
     */
    private function coordinator(): CoordinatorInterface
    {
        $store = config('schedule.cluster.store', null);
        if ($store === null || $store === '') {
            return new LocalCoordinator();
        }

        return new ClusterCoordinator((string) $store, (float) config('schedule.cluster.ttl', 30));
    }
}
