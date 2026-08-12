# 定时任务

用 `#[Cron('分 时 日 月 周')]` 声明，无需登记；`bin/kode cron` 自动发现并常驻调度（基于 `kode/process` 定时器）：

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
php bin/kode cron                      # 常驻运行所有 #[Cron] 任务（Ctrl+C 优雅退出）
php bin/kode cron --run=nightly-cleanup  # 立即手动触发一次（调试 / CI）
php bin/kode schedule:list             # 列出所有已发现任务
```

- `--run=<name>` 手动触发；`#[Cron(..., enabled: false)]` 临时停用；`#[Cron(cluster: true)]` 走分布式锁（需先配置协调存储）。
- 多应用：在 `config/schedule.php` 的 `paths` 追加更多目录 key。

---

## 底层引擎与生态包

- 当前 `#[Cron]` 常驻调度基于 **kode/process** 的 `Kode::cron()` / `Kode::cronCluster()` 定时器（与多进程 HTTP 服务同一运行时，天然常驻）。
- **kode/scheduling（v1.3）** 已纳入框架依赖栈，提供独立的 `Scheduler` 引擎：流畅式 `Task` 构造、Cron 表达式解析、防重叠锁（`withoutOverlapping`）、集群协调器（`CoordinatorInterface`）、运行报告（`RunReport`）。适合「由系统 crontab 每分钟触发 / `keepAlive()` 守护」的 standalone 调度场景。
- 框架执行引擎向 kode/scheduling 的委托迁移（含集群协调器接线）作为下一步，待单独设计与测试后合入，避免仓促改动影响现有常驻 `cron`。


