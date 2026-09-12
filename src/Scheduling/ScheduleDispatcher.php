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
     * **幂等**：同名任务替换框架侧记录并复用底层同一个 Task，不会重复派发。
     * 底层 kode/scheduling 的 register() 只做追加、无去重，若此处不拦，
     * 「卸载→重装→重扫」会产生两条同名引擎任务、到期被派发两次。
     *
     * @param list<ScheduledTask> $tasks
     * @return int 实际启用（会被调度）的任务数
     */
    public function register(array $tasks): int
    {
        $count = 0;
        $useCluster = false;

        foreach ($tasks as $task) {
            $this->upsert($task);

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
     * 幂等写入单条任务：首次注册建引擎 Task，重名则替换记录并就地刷新引擎 Task。
     */
    private function upsert(ScheduledTask $task): void
    {
        $engine = $this->scheduler->find($task->name);

        if ($engine !== null) {
            $index = $this->registeredIndex($task->name);
            if ($index === false) {
                $this->registered[] = $task;
            } else {
                $this->registered[$index] = $task;
            }
            $engine->cron($task->expression)
                ->enabled($task->enabled)
                ->description((string) ($task->description ?? ''));

            return;
        }

        // 按名称惰性查找当前生效记录，避免闭包长期持有陈旧副本：
        // 重名替换后旧闭包仍会跑到旧 handler，unregister() 后也会漏跑一次。
        $name = $task->name;
        $callback = function () use ($name): void {
            $current = $this->find($name);
            if ($current !== null) {
                $this->invoke($current);
            }
        };

        $this->registered[] = $task;
        $this->scheduler->call($task->name, $callback)
            ->cron($task->expression)
            ->enabled($task->enabled)
            ->description((string) ($task->description ?? ''));
    }

    /**
     * 按任务名查注册表（不含未启用的过滤，禁用任务同样可查）。
     */
    public function find(string $name): ?ScheduledTask
    {
        foreach ($this->registered as $task) {
            if ($task->name === $name) {
                return $task;
            }
        }

        return null;
    }

    /**
     * 按来源标签取任务（app / plugin:<name>），用于「回收某个插件的全部任务」。
     *
     * @return list<ScheduledTask>
     */
    public function bySource(string $source): array
    {
        $tasks = [];
        foreach ($this->registered as $task) {
            if ($task->source === $source) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * 运行时启停一条任务：同步替换注册表记录并更新底层 Task 的 enabled。
     *
     * 用于插件 pause/resume——暂停后任务不再到期派发，恢复后无需重启即重新调度。
     * 注意 {@see runOnce()} 仍按既有契约绕过 enabled（便于调试手动触发）。
     *
     * @return bool 状态是否真的翻转（未找到、或已是目标状态均返回 false）。
     *              要区分「不存在」与「已是目标状态」请用 {@see find()}——二者在本方法
     *              的返回值里合并，是为了让 {@see setEnabledBySource()} 能如实统计「实际改变数」。
     */
    public function setEnabled(string $name, bool $enabled = true): bool
    {
        $task = $this->find($name);
        if ($task === null || $task->enabled === $enabled) {
            return false;
        }

        $index = $this->registeredIndex($name);
        if ($index === false) {
            return false;
        }

        $this->registered[$index] = $task->withEnabled($enabled);
        $this->scheduler->find($name)?->enabled($enabled);

        return true;
    }

    /**
     * 按来源标签批量启停（插件暂停/恢复的全部定时任务）。
     *
     * @return int 实际改变状态的任务数
     */
    public function setEnabledBySource(string $source, bool $enabled = true): int
    {
        $changed = 0;
        foreach ($this->bySource($source) as $task) {
            if ($this->setEnabled($task->name, $enabled)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * 移除一条任务（框架侧彻底忘掉；底层引擎无删除 API，改为禁用占位）。
     *
     * 用于插件卸载——卸载后任务既不在 schedule:list 里，也不会再被派发。
     * 底层仍留一个 disabled 的 Task 占位（kode/scheduling 不提供删除），
     * 但 shouldRun() 对 disabled 直接短路，故不会再执行。
     *
     * @return bool 是否找到并移除
     */
    public function unregister(string $name): bool
    {
        $index = $this->registeredIndex($name);
        if ($index === false) {
            return false;
        }

        // array_splice 保序：注册表规模很小（数十条），不为换 O(1) 让 schedule:list
        // 的输出顺序在暂停/卸载后发生漂移。
        array_splice($this->registered, $index, 1);
        $this->scheduler->find($name)?->enabled(false);

        return true;
    }

    /**
     * 按来源标签批量移除（插件卸载时回收其全部定时任务）。
     *
     * @return int 实际移除的任务数
     */
    public function unregisterBySource(string $source): int
    {
        $removed = 0;
        foreach ($this->bySource($source) as $task) {
            if ($this->unregister($task->name)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * 注册表内某任务名的下标；不存在返回 false。
     */
    private function registeredIndex(string $name): int|false
    {
        foreach ($this->registered as $index => $task) {
            if ($task->name === $name) {
                return $index;
            }
        }

        return false;
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
     * 调用任务（走容器，与路由 handler 同约定：支持构造注入/属性注入）。
     *
     * 内联闭包任务（handler 非空，插件 addCron() 传闭包）直接调用闭包，不经容器解析。
     */
    private function invoke(ScheduledTask $task): void
    {
        $started = microtime(true);
        try {
            if ($task->handler !== null) {
                ($task->handler)();
            } else {
                $instance = $this->resolver !== null
                    ? ($this->resolver)($task->class)
                    : resolve($task->class);
                $instance->{$task->method}();
            }
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
