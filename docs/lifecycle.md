# 运维与生命周期

生产可观测的「健康探针 + 启动/停机钩子」，全部走 kode 生态事件与应用生命周期。

## 一、健康检查

`HealthChecker` 聚合多组件探测，结果由 `config/health.php` 的开关控制：

```php
// config/health.php
return [
    'checks' => [
        'db'    => true,   // Db::getConnections + SELECT 1
        'cache' => false,  // CacheManager set/get
        'queue' => false,  // Queue::connection
    ],
];
```

- 组件未配置连接时返回 `not_configured` 而非失败，避免误杀；
- 自定义检查：传入闭包 `fn() => ['status' => 'ok', 'message' => '']`；
- `app` 始终返回 `ok`。

### 端点

| 路径 | 含义 |
| --- | --- |
| `/ping` | 极简存活（`pong`），无组件探测 |
| `/health` | 综合健康，含 `components` 明细 |
| `/health/ready` | 就绪探针，组件未 `ok` 时返回 `503` |

`/health/ready` 用于 K8s `readinessProbe`：未就绪（依赖未连上）即 `503`，流量不被打入。

```json
GET /health
{
  "healthy": true,
  "components": {
    "db":  {"status": "ok",   "message": "SELECT 1"},
    "app": {"status": "ok",   "message": ""}
  }
}
```

## 二、启动与停机钩子

框架通过 kode/event 派发生命周期事件，业务可监听做初始化 / 资源释放：

| 事件 | 触发时机 | 携带 |
| --- | --- | --- |
| `Kode\Framework\Lifecycle\ApplicationBooted` | 应用首次启动完成（多进程下 master 与每个 worker 各触发一次） | `basePath`、`env` |
| `Kode\Framework\Lifecycle\WorkerStarting` | 单个 worker 开始接客前（应用已就绪） | `workerId` |
| `Kode\Framework\Lifecycle\WorkerStopping` | 收到 SIGTERM/SIGINT、优雅停机前 | `workerId` |

```php
use Kode\Framework\Lifecycle\ApplicationBooted;
use Kode\Framework\Lifecycle\WorkerStarting;
use Kode\Framework\Lifecycle\WorkerStopping;

app()->listen(ApplicationBooted::class, function (ApplicationBooted $e): void {
    // 进程级一次性初始化：预热缓存、注册信号、启动后台周期任务
});

app()->listen(WorkerStarting::class, function (WorkerStarting $e): void {
    // 每 worker 独立预热连接池 / 本地缓存，按 $e->workerId 区分
});

app()->listen(WorkerStopping::class, function (WorkerStopping $e): void {
    // 优雅停机：释放连接、落盘、取消订阅、通知注册中心下线（须在宽限期内完成）
});
```

> 监听方式与普通事件订阅一致（见 [事件](events.md)）。

## 三、部署提示

- 用进程管理器（systemd / supervisord / k8s）拉起 `php bin/kode serve`；
- `readinessProbe` 指向 `/health/ready`，`livenessProbe` 指向 `/ping`；
- 生产 `config/health.php` 按需开启 `db` / `cache` / `queue` 探测。

## 四、优雅停机时序

`serve` 进程的停机细节见 [多进程 HTTP 服务](http-server.md) 第 4 节，要点：

1. 收到 `SIGTERM`/`SIGINT` 后 master **停止接收新连接**；
2. 广播 `WorkerStopping`（业务在监听器里做快速收尾）；
3. 在途请求在 `config/server.php` 的 `graceful_shutdown_timeout`（默认 30s）内排空，超时强制退出；
4. 各 worker 退出后 master 退出。

`WorkerStopping` 监听器里**不要**做超过宽限期的操作（如同步刷大表、外呼），超出部分会与进程退出竞争；重活应落盘为任务由调度兜底。

> 监听方式与普通事件订阅一致（`app()->listen(...)` 或 `Kode\Event\Dispatcher::listen`，见 [事件](events.md)）。
