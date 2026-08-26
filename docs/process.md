# 自定义进程（常驻 Worker）

框架内置多进程常驻能力（`kode/process`）：把「周期性的后台工作」写成 Worker 类，启动后常驻运行，与 HTTP 服务解耦。典型场景：心跳上报、消息队列消费者、定时清理器、拉取外部数据。

## 1. 写一个 Worker

`app/process/HeartbeatWorker.php`（继承 `Kode\Framework\Process\Worker`）：

```php
<?php

declare(strict_types=1);

namespace app\process;

use Kode\Framework\Process\Worker;

final class HeartbeatWorker extends Worker
{
    public function name(): string
    {
        return 'heartbeat';          // 唯一名称
    }

    public function interval(): float
    {
        return 5.0;                  // 每 5 秒执行一次 handle()
    }

    public function handle(): void
    {
        $line = sprintf("[%s] heartbeat ok\n", date('Y-m-d H:i:s'));
        @file_put_contents(base_path('storage/heartbeat.log'), $line, FILE_APPEND);
    }
}
```

**三个必须实现的方法**：

| 方法 | 作用 |
| --- | --- |
| `name()` | worker 唯一名称（进程管理、日志标识用） |
| `interval()` | 轮询间隔（秒），常驻循环按此周期调用 `handle()` |
| `handle()` | 单次工作量；框架在一个稳定进程中反复调用 |

可选覆写：`instances()` 返回并行子进程数（默认 1）。

## 2. 声明启用

`config/process.php` 的 `workers` 数组注册：

```php
return [
    'workers' => [
        // 写法 1：无参 worker 直接写类名
        app\process\HeartbeatWorker::class,

        // 写法 2：带构造参数的 worker
        // ['class' => app\process\CleanupWorker::class, 'config' => ['ttl' => 3600]],

        // 写法 3：声明式增强（count/interval/once/slots 均可选，见「声明式增强」）
        // ['class' => app\process\RecurringScanWorker::class, 'count' => 3, 'interval' => 5.0],
    ],
];
```

> 框架启动时会自动实例化并把 worker 注册进 `ProcessManager`（门面 `Process` / 助手 `process()`）。

## 3. 运行

```bash
php bin/kode console process:check   # 校验 worker（不 fork 子进程，安全预览）
php bin/kode console process:start   # 启动常驻（fork 子进程）
php bin/kode console process:list    # 查看运行中的进程
```

- `process:check` 只做启动前验证（类存在、构造可解析），**不 fork**，联调用这个。
- 生产环境由进程管理器（Supervisor / systemd）拉起 `php bin/kode console process:start`，配合 [部署指南](deployment.md)。

## 4. 进阶

- **多实例**：`instances()` 返回 N → fork N 个子进程并行执行（如多消费者）。
- **构造注入**：Worker 构造参数由容器解析（即可注入服务/仓库），`config/process.php` 的 `config` 键会作为构造参数传入。
- **与队列协作**：常驻 Worker 里调用 `queue()->pop()` / `dispatch()` 即可充当消费者；更完整的队列消费用 `php bin/kode console queue:work`（见 [队列](queue.md)）。
- **定时任务 vs 进程**：短周期系统类任务用 `#[Cron]` 定时任务（[定时任务](scheduling.md)）；需要长连接、流式处理、精确资源控制时用自定义进程。

## 5. 声明式增强：count / interval / once / slots

配置数组（写法 3）支持在 `class`（+ 可选 `config`）之上叠加四个**声明键**，直接覆盖 worker 类默认行为，无需改类代码：

| 键 | 类型 | 含义 |
| --- | --- | --- |
| `count` | int | 并行实例数（等价覆写 `instances()`，fork N 个子进程） |
| `interval` | float | 轮询间隔秒（等价覆写 `interval()`） |
| `once` | bool | `true` = 一次性任务：启动同步执行一遍即退出，不 fork、不常驻 |
| `slots` | int[] | 仅这些实例执行；`[0]` = 仅主进程槽位，其余实例存活占位 |

```php
'workers' => [
    // 4 个并行的消费者，每 1 秒拉取一次
    ['class' => app\process\ConsumerWorker::class, 'count' => 4, 'interval' => 1.0],

    // 只跑一次（如启动时预热缓存），且只在主进程槽位执行
    ['class' => app\process\WarmupWorker::class, 'once' => true, 'slots' => [0]],
],
```

### Worker 类同名声明（声明键未给时生效）

类上覆写以下方法可达到同样效果；配置声明键**优先于**类默认值：

```php
final class WarmupWorker extends Worker
{
    public function slots(): array { return [0]; }    // [] = 全部实例；[0] = 仅主进程槽位

    public function once(): bool   { return true; }   // 一次性执行

    public function handle(int $slot = 0): void       // 可选新签名：感知当前执行槽位
    {
        if ($slot === 0) {
            // 仅实例 0 干活（如「只跑一次」的清扫、预热）
        }
    }
}
```

### 槽位语义与隔离

- `slots` 与 `count` 同时给出时，实际 fork 数取 **count 与 slots 的交集**（`effectiveSlots`），越界槽位自动忽略；
- 框架把多实例 worker 按生效槽位拆成多个**单槽位 Daemon**（`SlotWorker`）：每个槽位拥有独立的监督进程与重生预算，**崩溃隔离更彻底**——某个槽位崩溃不影响其他槽位；
- 底层运行器由 `kode/process` Daemon（v5.2.31+）提供：fork 多进程 + Timer 周期 + 监督重生 + 优雅退出，框架只做声明与拆槽位的薄适配；
- 典型分工：主进程槽位（slot 0）做「单点任务」（心跳上报、清扫），多槽位做「可分片任务」（队列消费按槽位取分片）。

### 一次性任务（once）

`once: true` 的 worker 走 `ProcessManager::runOnce()`：同步执行一遍 `handle()` 即退出，**不 fork、不进入轮询循环**。适合「随进程启动做一次初始化 / 预热 / 发一条消息」这类场景。

> 生产由进程管理器（Supervisor / systemd）拉起 `process:start` 即可；`process:check` 不 fork，联调 / CI 里用它做启动前验证。

> 示例见 `app/process/HeartbeatWorker.php`（已注册在 `config/process.php`）。