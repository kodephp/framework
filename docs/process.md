# 自定义进程（常驻 Worker）

框架内置多进程常驻能力（`kode/process`）：把「周期性的后台工作」写成 Worker 类，常驻运行，与 HTTP 服务**解耦**。典型场景：心跳上报、消息队列消费者、定时清理器、拉取外部数据。

> **先厘清两件事（最容易搞混）**：
> 1. **注册 ≠ 运行**。`config/process.php` 里声明 worker，只是把它们**注册**进 `ProcessManager`（框架启动时实例化、可被 `Process` 门面引用）；它们**不会自动 fork 运行**。真正把它们变成常驻进程，是另一条命令 `php bin/kode console process:start`。
> 2. **`bin/kode serve`（HTTP 服务）不会启动这些业务 worker**。`serve` 只负责 HTTP 请求处理进程池（`--workers` 个请求 worker）；业务常驻进程由 `process:start` 单独拉起。两者是**两条独立的进程树**，互不包含。
>
> 所以「框架启动命令已经按 config 启动进程了」——准确说：`serve` 启动的是 HTTP 进程池，业务 worker 仍要你显式 `process:start`。它们分工明确、也不冲突（见第 6 节）。

## 0. 与 HTTP 服务（serve）的关系

| 命令 | 拉起什么 | 进程池 | 典型用途 |
| --- | --- | --- | --- |
| `bin/kode serve` | HTTP 运行时（kode/process Daemon） | N 个**请求 worker**（由 `--workers` 决定） | 接收 / 响应 HTTP 请求 |
| `bin/kode console process:start` | 你注册的**业务 worker**（heartbeat / 消费者 / 清理器…） | 每个 worker 按 `count` / `slots` fork | 后台周期任务、长连接、队列消费 |

- 两者各自独立运行、各自有监督与重生；一条挂了不影响另一条。
- 生产里通常把 `serve` 和 `process:start` 都交给进程管理器（Supervisor / systemd）作为**两个独立 program** 拉起，而不是互相嵌套。

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

> 框架启动时会自动**注册**这些 worker 进 `ProcessManager`（门面 `Process` / 助手 `process()` 可引用），但**不会自动运行**——需 `process:start` 才 fork 常驻进程。

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

## 6. 会不会和现有进程冲突？

不会，原因如下：

- **独立进程树 + 独立 pid 文件**：每个 worker 有唯一 `name()`，`process:start` 为它生成独立 pid 文件（`sys_get_temp_dir()/kode-worker-<name>.pid`）。同名 worker 不会起两份（pid 文件做互斥占位）；不同 worker 各有各的文件，互不踩。
- **默认不占用 HTTP 端口**：worker 只跑 `handle()` 周期任务，不监听任何端口；只有你**主动**在 worker 里 `new Server` / 绑端口时才可能冲突——那种场景请单独规划端口，或干脆放进 `serve` 的 HTTP 进程。
- **与 `serve` 的 HTTP 进程池天然隔离**：`serve` 的请求 worker 和 `process:start` 的业务 worker 是两套 Daemon，一方崩溃 / 重启不影响另一方（这正是「解耦」的设计目的）。

注意事项：

- **不要同机重复跑 `process:start`**：一份 worker 配置对应一个 `process:start` 实例（交给 Supervisor 管一个 program 即可），重复拉起会因 pid 文件互斥而报错，而不是静默起双份。
- 多个**不同** worker（`name` 不同）并行完全没问题；同一 worker 想多实例请用 `count` / `slots`，不要手动起多个 `process:start`。