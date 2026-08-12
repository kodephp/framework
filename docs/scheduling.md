# 定时任务

用 `#[Cron('分 时 日 月 周')]` 声明，无需登记；`bin/kode cron` 自动发现并调度。执行引擎由 **kode/scheduling** 驱动（cron 匹配 / 防重叠互斥 / 集群协调 / keepAlive 守护循环均由包负责），框架只保留「约定优于配置」的 `#[Cron]` 自动发现（类级 + 方法级，含禁用）。

```php
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

#[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理')]
final class CleanupTask extends Task
{
    public function handle(): void
    {
        // 业务逻辑；构造依赖由容器自动注入
    }
}
```

```bash
php bin/kode cron                       # 常驻运行所有 #[Cron] 任务（Ctrl+C 优雅退出）
php bin/kode cron --run-once            # 单轮执行一次（适合由系统 crontab 每分钟触发）
php bin/kode cron --run=nightly-cleanup # 立即手动触发某条任务一次（调试 / CI）
php bin/kode schedule:list              # 列出所有已发现任务
```

- `--run=<name>` 手动触发某条；`#[Cron(..., enabled: false)]` 临时停用；`#[Cron(cluster: true)]` 走分布式锁（需先配置协调存储，见下）。
- 多应用：在 `config/schedule.php` 的 `paths` 追加更多目录 key（如 `'admin' => 'modules/admin/Tasks'`）。
- 插件：`discover_plugins => true` 后扫描 `plugins/<name>/src/Tasks`，来源标记为 `plugin:<name>`。

---

## 集群「至多一次」

任务上标 `#[Cron(cluster: true)]` 即要求集群内同一调度时刻至多执行一次。前提是已在 `config/schedule.php` 的 `cluster` 段配置协调存储：

```php
'cluster' => [
    'store' => env('SCHEDULE_CLUSTER_STORE', ''),   // 同 Kode\Process\Cluster::make()：'redis' / 'file' ...
    'ttl'   => (float) env('SCHEDULE_CLUSTER_TTL', 30), // 派发锁 TTL（秒），应 >= 单轮任务最大耗时
],
```

- 配置了 `store`：框架挂上 `ClusterCoordinator`（实现 kode/scheduling 的 `CoordinatorInterface`），把「本节点本轮是否派发」裁决委托给 kode/process 分布式锁；只有抢到锁的节点派发。
- 未配置（默认）：回退 `LocalCoordinator`（恒派发）——单进程/单机安全，多机可能重复。
- **失败降级**：协调存储不可用时 `shouldDispatch()` 降级为本地派发（服务可用优先），不会 fatal——与 kode/process `ClusterCron` 同理念。

> 说明：kode/process 的 `Kode::cron()` 常驻定时器也提供 `#[Cron(cluster: true)]` 的 `cronCluster` 协调（已 try/catch 失败降级）。框架调度现统一走 kode/scheduling 引擎，集群协调通过 `ClusterCoordinator` 接线，二者语义一致。

---

## 底层引擎与生态包

- 执行引擎：**kode/scheduling** —— 流畅式 `Task` 构造（`call()->cron()->enabled()->description()`）、Cron 表达式解析（5 段 / 6 段秒级 / `@宏`）、防重叠锁（`withoutOverlapping`）、集群协调器（`CoordinatorInterface`）、运行报告（`RunReport`）。
- 运行模型：默认 `keepAlive()` 守护循环（含 SIGINT/SIGTERM 优雅退出；检测到秒级任务自动提升轮询精度为每秒）。`--run-once` 则单轮 `run()`，适合由系统 crontab 每分钟触发。
- 框架薄壳价值：仅保留 `TaskScanner` 自动发现 `#[Cron]` 属性 + 把 `ScheduledTask` 映射到 kode/scheduling `Task` 并接好容器注入（`ScheduleDispatcher`）。
