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
 *
 * v1.6.0 增强（多租户权限收敛）：
 *  - 执行历史审计：logRun() 写入 kode_schedule_runs 表，记录每次执行的状态/耗时/错误；
 *  - 下次运行追踪：nextRun() 计算任务下次到期时刻，供 UI 展示与告警；
 *  - 任务级互斥锁：acquireLock()/releaseLock()/isLocked() 防止同一任务并发执行；
 *  - 动态任务注册：addCron() 运行时添加租户级闭包任务，无需重启；
 *  - 租户作用域：byTenant() 按 tenant_id 过滤任务，支持多租户隔离；
 *  - 健康指标：stats() 返回任务执行成功率/平均耗时/连续失败次数。
 */
final class ScheduleDispatcher
{
    /** 已注册的任务（含未启用的占位，便于 list 展示）。 */
    private array $registered = [];

    /** 底层执行引擎（kode/scheduling）。 */
    private Scheduler $scheduler;

    /** 任务级互斥锁（单节点内防并发）。 */
    private array $locks = [];

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

    // ─────────────────────────────────────────────
    // 注册与注销
    // ─────────────────────────────────────────────

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
     * 运行时动态注册一条闭包任务（租户 API 创建自定义定时任务）。
     *
     * 不依赖 #[Cron] 属性扫描，直接构造 ScheduledTask 并 upsert。
     * 用于多租户场景：每个租户可创建自己的定时任务，无需重启服务。
     *
     * @param string              $name       任务名（唯一标识）
     * @param string              $expression cron 表达式
     * @param \Closure            $handler    执行闭包
     * @param array               $options    可选：description, cluster, tenantId, metadata
     * @return ScheduledTask 注册后的任务对象
     */
    public function addCron(
        string $name,
        string $expression,
        \Closure $handler,
        array $options = [],
    ): ScheduledTask {
        $task = new ScheduledTask(
            class: 'Closure',
            method: '__invoke',
            expression: $expression,
            name: $name,
            description: (string) ($options['description'] ?? ''),
            enabled: (bool) ($options['enabled'] ?? true),
            cluster: (bool) ($options['cluster'] ?? false),
            source: (string) ($options['source'] ?? 'dynamic'),
            handler: $handler,
            tenantId: (int) ($options['tenantId'] ?? 0),
            metadata: (array) ($options['metadata'] ?? []),
        );

        $this->upsert($task);

        return $task;
    }

    // ─────────────────────────────────────────────
    // 查询与过滤
    // ─────────────────────────────────────────────

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
     * 按租户 ID 取任务（多租户隔离：仅返回属于该租户的任务）。
     *
     * tenantId = 0 时返回全部全局任务 + 全部租户任务（超管视角）。
     *
     * @return list<ScheduledTask>
     */
    public function byTenant(int $tenantId = 0): array
    {
        if ($tenantId === 0) {
            return $this->registered;
        }

        $tasks = [];
        foreach ($this->registered as $task) {
            if ($task->tenantId === $tenantId || $task->tenantId === 0) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * 按租户 ID + 来源标签组合过滤（租户视角的插件任务）。
     *
     * @return list<ScheduledTask>
     */
    public function byTenantAndSource(int $tenantId, string $source): array
    {
        return array_filter(
            $this->byTenant($tenantId),
            static fn(ScheduledTask $t) => $t->source === $source,
        );
    }

    /**
     * 任务下次运行时刻（基于当前 cron 表达式与当前时间计算）。
     *
     * 用于 UI 展示「下次运行」与告警系统判断任务是否 overdue。
     * 禁用任务返回 null。
     */
    public function nextRun(string $name): ?\DateTimeImmutable
    {
        $task = $this->find($name);
        if ($task === null || !$task->enabled) {
            return null;
        }

        return $this->computeNextRun($task->expression);
    }

    /**
     * 全部任务的下次运行时刻映射（name => DateTimeImmutable|null）。
     *
     * @return array<string, \DateTimeImmutable|null>
     */
    public function allNextRuns(): array
    {
        $runs = [];
        foreach ($this->registered as $task) {
            $runs[$task->name] = $task->enabled ? $this->computeNextRun($task->expression) : null;
        }

        return $runs;
    }

    // ─────────────────────────────────────────────
    // 运行时启停
    // ─────────────────────────────────────────────

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
     * 按租户 ID 批量启停该租户的全部任务。
     *
     * @return int 实际改变状态的任务数
     */
    public function setEnabledByTenant(int $tenantId, bool $enabled = true): int
    {
        if ($tenantId === 0) {
            return 0; // 不允许对全局任务批量操作
        }

        $changed = 0;
        foreach ($this->byTenant($tenantId) as $task) {
            if ($task->tenantId === $tenantId && $this->setEnabled($task->name, $enabled)) {
                $changed++;
            }
        }

        return $changed;
    }

    // ─────────────────────────────────────────────
    // 移除
    // ─────────────────────────────────────────────

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
        unset($this->locks[$name]);

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
     * 按租户 ID 批量移除（租户注销时回收其全部定时任务）。
     *
     * @return int 实际移除的任务数
     */
    public function unregisterByTenant(int $tenantId): int
    {
        if ($tenantId === 0) {
            return 0; // 不允许移除全局任务
        }

        $removed = 0;
        foreach ($this->byTenant($tenantId) as $task) {
            if ($task->tenantId === $tenantId && $this->unregister($task->name)) {
                $removed++;
            }
        }

        return $removed;
    }

    // ─────────────────────────────────────────────
    // 执行
    // ─────────────────────────────────────────────

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

    // ─────────────────────────────────────────────
    // 执行历史审计
    // ─────────────────────────────────────────────

    /**
     * 记录一次任务执行结果到 kode_schedule_runs 表（审计日志）。
     *
     * 每次任务执行后调用，记录状态/耗时/错误，供 UI 展示执行历史与告警系统判断任务健康。
     * 表不存在或写入失败时仅告警，不阻断主流程（fail-safe）。
     *
     * @param string      $taskName   任务名
     * @param string      $status     执行状态：success / failed / skipped
     * @param Throwable|null $error   失败时的异常对象
     * @param int         $durationMs 耗时（毫秒）
     * @param int         $tenantId   租户 ID（0 = 全局）
     * @param string|null $nodeId     执行节点标识（多机部署时区分来源）
     * @return bool 是否写入成功
     */
    public function logRun(
        string $taskName,
        string $status,
        ?Throwable $error = null,
        int $durationMs = 0,
        int $tenantId = 0,
        ?string $nodeId = null,
    ): bool {
        try {
            $db = \Kode\Database\Db\Db::class;
            $db::statement(
                "CREATE TABLE IF NOT EXISTS kode_schedule_runs (
                    id BIGSERIAL PRIMARY KEY,
                    task_name VARCHAR(128) NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    duration_ms INTEGER NOT NULL DEFAULT 0,
                    error_message TEXT NULL,
                    tenant_id INTEGER NOT NULL DEFAULT 0,
                    node_id VARCHAR(128) NULL,
                    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )"
            );
            $db::statement(
                "CREATE INDEX IF NOT EXISTS idx_schedule_runs_task_name ON kode_schedule_runs (task_name, started_at DESC)"
            );
            $db::statement(
                "CREATE INDEX IF NOT EXISTS idx_schedule_runs_status ON kode_schedule_runs (status, started_at DESC)"
            );

            $errorMessage = $error !== null ? mb_substr($error->getMessage(), 0, 2000) : null;
            $db::insert(
                "INSERT INTO kode_schedule_runs (task_name, status, duration_ms, error_message, tenant_id, node_id) VALUES (?, ?, ?, ?, ?, ?)",
                [$taskName, $status, $durationMs, $errorMessage, $tenantId, $nodeId]
            );

            return true;
        } catch (\Throwable $e) {
            logger()->warning("调度执行日志写入失败（{$taskName}）：" . $e->getMessage());

            return false;
        }
    }

    /**
     * 查询某任务的执行历史（最近 N 条）。
     *
     * @return list<array<string, mixed>>
     */
    public function runHistory(string $taskName, int $limit = 50): array
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $rows = $db::select(
                "SELECT id, task_name, status, duration_ms, error_message, tenant_id, node_id, started_at
                 FROM kode_schedule_runs WHERE task_name = ? ORDER BY started_at DESC LIMIT ?",
                [$taskName, $limit]
            );

            return $rows ?? [];
        } catch (\Throwable $e) {
            logger()->warning("查询调度执行历史失败（{$taskName}）：" . $e->getMessage());

            return [];
        }
    }

    /**
     * 查询某租户的调度执行历史（最近 N 条，跨任务）。
     *
     * @return list<array<string, mixed>>
     */
    public function tenantRunHistory(int $tenantId, int $limit = 100): array
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $rows = $db::select(
                "SELECT id, task_name, status, duration_ms, error_message, tenant_id, node_id, started_at
                 FROM kode_schedule_runs WHERE tenant_id = ? ORDER BY started_at DESC LIMIT ?",
                [$tenantId, $limit]
            );

            return $rows ?? [];
        } catch (\Throwable $e) {
            logger()->warning("查询租户调度历史失败（tenant={$tenantId}）：" . $e->getMessage());

            return [];
        }
    }

    /**
     * 清理过期执行历史（保留最近 N 天，防止表膨胀）。
     *
     * @param int $retentionDays 保留天数
     * @return int 清理的记录数
     */
    public function pruneRunHistory(int $retentionDays = 30): int
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $deleted = $db::delete(
                "DELETE FROM kode_schedule_runs WHERE started_at < NOW() - INTERVAL '{$retentionDays} days'"
            );

            return (int) $deleted;
        } catch (\Throwable $e) {
            logger()->warning("清理调度执行历史失败：" . $e->getMessage());

            return 0;
        }
    }

    // ─────────────────────────────────────────────
    // 任务级互斥锁（单节点内防并发）
    // ─────────────────────────────────────────────

    /**
     * 尝试获取任务锁（单节点内防并发执行）。
     *
     * 与 cluster 模式互补：cluster 模式保证多机至多一次，本锁保证单机内不并发。
     * 用于长耗时任务——上一次未执行完时，跳过本轮避免堆积。
     *
     * @param int $ttlSeconds 锁超时（秒），防止进程崩溃后死锁
     * @return bool 是否获取成功
     */
    public function acquireLock(string $name, int $ttlSeconds = 300): bool
    {
        $now = microtime(true);
        $existing = $this->locks[$name] ?? null;

        if ($existing !== null) {
            if ($now - $existing < $ttlSeconds) {
                return false; // 锁未过期，获取失败
            }
            // 锁已过期，自动释放后重新获取
        }

        $this->locks[$name] = $now;

        return true;
    }

    /**
     * 释放任务锁。
     */
    public function releaseLock(string $name): void
    {
        unset($this->locks[$name]);
    }

    /**
     * 检查任务是否被锁定（正在执行中）。
     */
    public function isLocked(string $name): bool
    {
        $existing = $this->locks[$name] ?? null;
        if ($existing === null) {
            return false;
        }

        return (microtime(true) - $existing) < 300; // 默认 5 分钟 TTL
    }

    /**
     * 所有被锁定的任务名。
     *
     * @return list<string>
     */
    public function lockedTasks(): array
    {
        $locked = [];
        $now = microtime(true);
        foreach ($this->locks as $name => $acquired) {
            if ($now - $acquired < 300) {
                $locked[] = $name;
            }
        }

        return $locked;
    }

    // ─────────────────────────────────────────────
    // 健康指标
    // ─────────────────────────────────────────────

    /**
     * 获取任务健康指标（执行统计）。
     *
     * 从 kode_schedule_runs 表聚合：总执行数、成功数、失败数、跳过数、
     * 平均耗时、最大耗时、连续失败次数、最后执行时间。
     *
     * @param string|null $taskName 指定任务名；null 时返回全部任务的汇总
     * @return array<string, mixed>
     */
    public function stats(?string $taskName = null): array
    {
        try {
            $db = \Kode\Database\Db\Db::class;

            if ($taskName !== null) {
                $row = $db::selectOne(
                    "SELECT
                        COUNT(*) AS total,
                        COUNT(*) FILTER (WHERE status = 'success') AS succeeded,
                        COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                        COUNT(*) FILTER (WHERE status = 'skipped') AS skipped,
                        AVG(duration_ms) AS avg_duration_ms,
                        MAX(duration_ms) AS max_duration_ms,
                        MAX(started_at) AS last_run_at
                     FROM kode_schedule_runs WHERE task_name = ? GROUP BY task_name",
                    [$taskName]
                );

                $result = $row ?? [
                    'total' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'skipped' => 0,
                    'avg_duration_ms' => 0,
                    'max_duration_ms' => 0,
                    'last_run_at' => null,
                ];

                // 计算成功率
                $result['success_rate'] = $result['total'] > 0
                    ? round(($result['succeeded'] / $result['total']) * 100, 1)
                    : 100.0;

                // 连续失败次数（从最近记录往前数）
                $result['consecutive_failures'] = $this->consecutiveFailures($taskName);

                return $result;
            }

            // 全部任务汇总
            $rows = $db::select(
                "SELECT
                    task_name,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'success') AS succeeded,
                    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                    AVG(duration_ms) AS avg_duration_ms,
                    MAX(started_at) AS last_run_at
                 FROM kode_schedule_runs GROUP BY task_name ORDER BY total DESC LIMIT 100"
            );

            $result = [];
            foreach ($rows ?? [] as $row) {
                $result[$row['task_name']] = [
                    'total' => (int) $row['total'],
                    'succeeded' => (int) $row['succeeded'],
                    'failed' => (int) $row['failed'],
                    'avg_duration_ms' => (int) round((float) $row['avg_duration_ms']),
                    'last_run_at' => $row['last_run_at'],
                    'success_rate' => $row['total'] > 0
                        ? round(($row['succeeded'] / $row['total']) * 100, 1)
                        : 100.0,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            logger()->warning("查询调度统计失败：" . $e->getMessage());

            return $taskName !== null ? [
                'total' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'avg_duration_ms' => 0,
                'max_duration_ms' => 0,
                'last_run_at' => null,
                'success_rate' => 100.0,
                'consecutive_failures' => 0,
            ] : [];
        }
    }

    /**
     * 计算某任务的连续失败次数（从最近记录往前数，遇到 success 停止）。
     */
    private function consecutiveFailures(string $taskName): int
    {
        try {
            $db = \Kode\Database\Db\Db::class;
            $rows = $db::select(
                "SELECT status FROM kode_schedule_runs WHERE task_name = ? ORDER BY started_at DESC LIMIT 20",
                [$taskName]
            );

            $count = 0;
            foreach ($rows ?? [] as $row) {
                if ($row['status'] === 'failed') {
                    $count++;
                } else {
                    break;
                }
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────
    // 内部方法
    // ─────────────────────────────────────────────

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
     * 已注册任务数（含禁用）。
     */
    public function count(): int
    {
        return count($this->registered);
    }

    /**
     * 已启用的任务数。
     */
    public function enabledCount(): int
    {
        $count = 0;
        foreach ($this->registered as $task) {
            if ($task->enabled) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 调用任务（走容器，与路由 handler 同约定：支持构造注入/属性注入）。
     *
     * 内联闭包任务（handler 非空，插件 addCron() 传闭包）直接调用闭包，不经容器解析。
     *
     * v1.6.0：自动获取任务级互斥锁，执行完毕后释放；并写入执行历史。
     */
    private function invoke(ScheduledTask $task): void
    {
        $nodeId = $this->resolveNodeId();
        $lockTtl = (int) ($task->metadata['lock_ttl'] ?? 300);

        // 获取任务锁（防并发）
        if (!$this->acquireLock($task->name, $lockTtl)) {
            logger()->info(sprintf('[schedule] ⊘ %s 跳过（上一轮仍在执行）', $task->name));
            $this->logRun($task->name, 'skipped', null, 0, $task->tenantId, $nodeId);

            return;
        }

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
            $this->logRun($task->name, 'success', null, (int) $ms, $task->tenantId, $nodeId);
        } catch (Throwable $e) {
            $ms = round((microtime(true) - $started) * 1000, 2);
            logger()->error(
                sprintf('[schedule] ✗ %s 执行失败：%s', $task->name, $e->getMessage()),
                ['exception' => $e]
            );
            $this->logRun($task->name, 'failed', $e, (int) $ms, $task->tenantId, $nodeId);
        } finally {
            $this->releaseLock($task->name);
        }
    }

    /**
     * 选择协调器：schedule.cluster.store 非空即启用集群锁协调，否则本地恒派发。
     *
     * 注意该键只作开关：锁落在哪个后端由进程级 Cluster::store() 决定（见 {@see ClusterCoordinator}）。
     */
    private function coordinator(): CoordinatorInterface
    {
        $store = config('schedule.cluster.store', null);
        if ($store === null || $store === '') {
            return new LocalCoordinator();
        }

        return new ClusterCoordinator((string) $store, (float) config('schedule.cluster.ttl', 30));
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
     * 解析节点标识（多机部署时区分执行来源）。
     */
    private function resolveNodeId(): string
    {
        return (string) (gethostname() ?: uniqid('node_', true));
    }

    /**
     * 计算 cron 表达式的下次运行时刻。
     *
     * 简化的 cron 解析（支持 5 段标准格式：分 时 日 月 周）。
     * 完整 cron 解析由 kode/scheduling 的 Scheduler 负责，此处仅供 UI 展示。
     */
    private function computeNextRun(string $expression): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        $parts = explode(' ', trim($expression));
        if (count($parts) < 5) {
            return null;
        }

        [$min, $hour, $day, $month, $weekday] = $parts;

        // 简化计算：逐分钟向后查找
        for ($i = 1; $i <= 525600; $i++) { // 最多查找 1 年
            $candidate = $now->modify("+{$i} minutes");
            if ($this->cronMatches($candidate, $min, $hour, $day, $month, $weekday)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 判断某个时刻是否匹配 cron 表达式。
     */
    private function cronMatches(
        \DateTimeImmutable $dt,
        string $min,
        string $hour,
        string $day,
        string $month,
        string $weekday,
    ): bool {
        if (!$this->cronFieldMatches($dt->format('i'), $min)) return false;
        if (!$this->cronFieldMatches($dt->format('G'), $hour)) return false;
        if (!$this->cronFieldMatches($dt->format('j'), $day)) return false;
        if (!$this->cronFieldMatches($dt->format('n'), $month)) return false;

        // 周匹配：PHP 的 N 返回 1-7（周一到周日），cron 的 0 代表周日
        $phpWeekday = (int) $dt->format('N'); // 1=Mon, 7=Sun
        $cronWeekday = $phpWeekday === 7 ? 0 : $phpWeekday;
        if (!$this->cronFieldMatches((string) $cronWeekday, $weekday)) return false;

        return true;
    }

    /**
     * 判断单个字段值是否匹配 cron 字段表达式。
     *
     * 支持：* / 数字 / 范围(1-5) / 列表(1,3,5) / 步长(* /5, 1-10/2)
     */
    private function cronFieldMatches(string $value, string $field): bool
    {
        $val = (int) $value;

        // 通配符
        if ($field === '*') {
            return true;
        }

        // 步长
        if (str_contains($field, '/')) {
            [$range, $step] = explode('/', $field, 2);
            $step = (int) $step;
            if ($range === '*') {
                return $val % $step === 0;
            }
            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                return $val >= (int) $start && $val <= (int) $end && ($val - (int) $start) % $step === 0;
            }
            return $val % $step === 0;
        }

        // 列表
        if (str_contains($field, ',')) {
            $fields = explode(',', $field);
            foreach ($fields as $f) {
                if ($this->cronFieldMatches($value, trim($f))) {
                    return true;
                }
            }

            return false;
        }

        // 范围
        if (str_contains($field, '-')) {
            [$start, $end] = explode('-', $field, 2);
            return $val >= (int) $start && $val <= (int) $end;
        }

        // 单值
        return $val === (int) $field;
    }
}
