# 多进程 HTTP 服务

## 1. 启动

```bash
php bin/kode serve                          # 默认 http://127.0.0.1:9527，worker = CPU 核数
php bin/kode serve --host 0.0.0.0 --port 8080 --workers 8
php bin/kode serve --watch                  # 开发期热重载（监听 .php 变化自动重启）
```

- 运行时：`kode/process` master-worker 多进程 —— master 负责端口绑定与**监督重生**，每个 worker 独立重建应用，数据库连接 / 缓存句柄 / JWT 密钥等可变状态按进程隔离；
- 底层不限于单一扩展：同一套代码可跑 Native / fiber / Swoole / Workerman，`serve` 是统一入口；
- CLI 参数优先级高于 `config/server.php`。

## 2. 配置（config/server.php）

| 键 | 默认 | 说明 |
| --- | --- | --- |
| `host` / `port` | `127.0.0.1` / `9527` | 监听地址，可经 `SERVER_HOST` / `SERVER_PORT` 环境变量注入 |
| `workers` | `0`（= CPU 核数） | worker 进程数；`SERVER_WORKERS` 可覆盖 |
| `max_request` | `0` | 单 worker 累计处理多少请求后自动回收（防内存泄漏；`0`=不回收） |
| `reuse_port` | `false` | 多 worker 高并发下端口绑定吞吐优化（依赖 OS 支持） |
| `name` | `kode-http` | 进程名 |
| `graceful_shutdown_timeout` | `30` | 优雅停机宽限（秒），见第 4 节 |
| `watch.dirs` / `watch.exclude` | 空 / 固定排除集 | `--watch` 的监听目录，见第 3 节 |

## 3. 热重载（serve --watch）

`HotReloadWatcher`（`src/Server/HotReloadWatcher.php`）是跨运行时通用的「nodemon」模式：

- 把真实 `serve` 作为**子进程**启动（`proc_open`，继承终端 stdio）；
- 父进程用 kode/process 的 `FileMonitor` 轮询（500ms 间隔）默认监听 `app` / `config` / `src` / `public` / `bin`（存在的才收），排除 `vendor` / `.git` / `storage` / `runtime` / `node_modules` 等；
- 检测到 `.php` 增/删/改 → 向子进程发 `SIGTERM` 优雅关停 → 重新拉起；子进程意外退出也会自动重新拉起；
- 启动时先 `tick()` 建基线，避免「首次全量新增」的误重启。

```bash
php bin/kode serve --watch          # 改代码即生效，Ctrl+C 整体退出
```

> 仅限开发环境。生产用进程管理器或 k8s 管理，不要挂 `--watch`。

## 4. 优雅停机

`kode/process` 收到 `SIGTERM`/`SIGINT` 后：**停止接收新连接 → 排空在途请求**，`graceful_shutdown_timeout` 就是在途请求的最长等待时间（超时强制退出）。`GracefulShutdown::track()` 负责跟踪在途请求，未完成的请求在时限内自然跑完。

- 设为 `0` 回退到 kode/process 内置默认（3s）；
- 生产建议：**长事务 / 大文件上传的 P99 耗时 < `graceful_shutdown_timeout` << k8s `terminationGracePeriodSeconds`（默认 30s）**，给 LB 摘流 + 进程最终退出留余量。

停机流程（自上而下）：

1. k8s / 进程管理器发 `SIGTERM`（可配 `preStop` 钩子先摘流）；
2. master 停止接收新连接，广播 `WorkerStopping` 事件（`workerId`）；
3. 各 worker 排空在途请求（超时强制退出），做资源回收；
4. worker 全部退出后 master 退出。

## 5. Worker 生命周期事件

每个 worker 进程启动 → 接客 → 退出，框架派发 `Kode\Framework\Lifecycle\*` 事件（`basePath` / `env` 字段见 [生命周期](lifecycle.md)）：

| 事件 | 时机 | 用途 |
| --- | --- | --- |
| `ApplicationBooted` | 每进程 boot 一次（master 预热与每个 worker 各一次） | 进程级一次性初始化：预热缓存、注册信号、起后台协程 |
| `WorkerStarting` | 单个 worker 开始接客前（应用已就绪） | worker 级：独立连接池、周期任务、就绪日志 |
| `WorkerStopping` | 收到 SIGTERM/SIGINT、优雅停机前 | 快速收尾：刷指标、关连接、注册中心下线 |

```php
use Kode\Framework\Lifecycle\WorkerStarting;
use Kode\Framework\Lifecycle\WorkerStopping;

app()->listen(WorkerStarting::class, function (WorkerStarting $e): void {
    // $e->workerId 区分；每个 worker 独立预热连接池 / 本地缓存
});
app()->listen(WorkerStopping::class, function (WorkerStopping $e): void {
    // 落盘 / 关闭连接 / 取消订阅，须在宽限期内完成
});
```

## 6. 常见调优

- **连接池**：worker 数 × 每 worker 连接池上限，别让数据库连接数爆掉（`config/database.php`）；
- **max_request**：长生命周期 worker 内存缓慢增长时开 `125`~`1000` 让 worker 周期回收；
- **reuse_port**：多 worker 高并发、CPU 核数多的机器可开，吞吐有增益但前提是 OS 支持；
- **graceful_shutdown_timeout**：与 k8s `terminationGracePeriodSeconds` 配套调整（见第 4 节），避免「进程被杀」或「宽限浪费」。