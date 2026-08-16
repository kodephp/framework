# kode/process 5.2.31 — Swoole 驱动并发 keep-alive 静默崩溃回归

> 用于转发 kode/process 上游维护者。框架侧（kode framework）已确认无辜、无法以 patch 持久修复（vendor 已 gitignore）。
> 临时兜底：改用 kode/process 的 **Workerman 驱动**（`KODE_RUNTIME=workerman`）压测/运行正常。
> **调整方向（具体改法）**：见 [`kode-process-fix-directions.md`](./kode-process-fix-directions.md) 的 **F2** 节——需先开 `SWOOLE_LOG_TRACE` + core_dump 取 C 层崩溃栈，再按「跨请求 stale 响应对象」或「Swoole 6.2.2 自身 bug」二选一修复。

## 环境

| 项 | 值 |
|---|---|
| kode/process | 5.2.31 |
| ext-swoole | 6.2.2 (`swoole_version()`) |
| PHP | 8.3.33 |
| OS | macOS 15 (Apple Silicon, 11 核) — 崩溃特征（worker 静默重启）与平台无关，Linux 大概率复现 |
| 触发入口 | `Kode::serve('http://...', [...])` 走默认 `runtime=auto`（Swoole 可用时选 Swoole 驱动 `SwooleRuntime`/`SwooleConnection`） |

## 症状

并发 keep-alive 负载下，**Swoole worker 静默崩溃并重启**，吞吐从单连接 ~160k rps 塌至 ~2k rps：

```
$ wrk -t 8 -c 200 -d 8s http://127.0.0.1:8094/ping
Requests/sec:   2002.05
Socket errors: connect 200, read 16105, write 0, timeout 0
```

- server log **无任何 PHP Fatal error、无任何 Swoole 错误输出**（进程直接消失后由 manager 拉起）。
- **单条 keep-alive socket 串发 300 次 `/ping` 全部 200 OK** → 仅「多 keep-alive 连接并发」触发，非单请求逻辑问题。

## 最小复现

任意 handler 经 `Kode::serve` 的 **Swoole 驱动**即复现，与业务代码无关：

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use Kode\Process\Kode;
use Kode\Process\Runtime\ConnectionInterface;
use Kode\Http\Response;

Kode::serve("http://127.0.0.1:8094", ['workers' => 11])
    ->on('message', static function (ConnectionInterface $conn, $message): void {
        $conn->sendResponse(new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}'), '1.1');
    })
    ->start();
```

压测：

```bash
wrk -t 8 -c 200 -d 8s http://127.0.0.1:8094/ping   # 塌方
# 单连接串发（如 `for i in $(seq 1 300); do curl ...; done`）全部 200 OK
```

## 已排除的变量（20+ 对照实验）

| 改动 | 结果 |
|---|---|
| handler 直写 `Swoole\Http\Response`（绕过 `sendResponse`） | 仍崩 |
| handler 返回最小静态响应（不调框架 `handle`） | 仍崩 |
| `gzipAuto=false` / 显式关 gzip | 仍崩 |
| `SwooleConnection::sendResponse` 内 `status()` 改 1-arg | 仍崩 |
| 去掉 `responded` 守卫（允许重复写） | 仍崩 |
| 强制 `SWOOLE_PROCESS` 模式（raw peer 用 PROCESS 正常） | 仍崩 |
| `enable_coroutine=false`（`cid=-1`，确认非协程） | 仍崩 |
| handler `try/catch` 写文件记录 `Throwable` | **无任何异常记录** |
| `Request::fromSwoole` 无共享可变状态 | 排除请求构造 |

→ 崩溃点位于 **kode/process Swoole 驱动层的请求派发/响应写出与 Swoole 6.2.2 的并发交互**，既非用户代码、亦非框架代码。

## 关键对照：Workerman / Native 驱动健康

同 commit、同 handler、仅切运行时：

```bash
KODE_RUNTIME=workerman BENCH_PORT=8095 php benchmarks/peers/kode_swoole_server.php
$ wrk -t 8 -c 200 -d 8s http://127.0.0.1:8095/ping
Requests/sec:   174480.56   # 零 socket error
```

→ 回归**仅限 Swoole 驱动**；Workerman / Native 驱动不受影响，**可作临时兜底运行时**。

## 怀疑点（供维护者定位）

- `SwooleRuntime` 默认 `SWOOLE_BASE` + `enable_coroutine=false`（每 worker 同步串行处理请求）。并发多 keep-alive 连接下，Swoole 6.2.2 的 BASE 模式请求处理路径与 `SwooleConnection::sendResponse`（`status()` + `header()` + `end($body)` + `responded` 守卫）存在并发交互问题。
- 静默崩溃（无 PHP / Swoole 日志）符合 **Swoole C 层 segfault** 特征。建议维护者复现时开启 `SWOOLE_LOG_TRACE` 并开启 `core_dump`，抓取崩溃栈定位具体 C 符号。
- 若 5.2.31 相对上一可用版本在 `SwooleConnection`/`SwooleRuntime` 有过改动，建议优先 diff 该区域（响应写出与 `responded` 守卫、BASE 模式下连接对象复用）。

## 复现脚本（框架仓库）

- `benchmarks/peers/kode_swoole_server.php`：`Kode::serve` 真实路径（默认 `runtime=auto` 即 Swoole）；`KODE_PROFILE=lean|default`、`KODE_RUNTIME=swoole|workerman|native`。
- `benchmarks/peers/run.sh`：6 peer 全量对标（kode·default / kode·lean 在 5.2.31 下 Swoole 驱动不可用）。
- `benchmarks/peers/run_workerman_kode.sh`：仅 Workerman 驱动的兜底对标（健康）。
- 压测客户端：`wrk -t 8 -c 200 -d 8s <url>`；注意本机若存在 `HTTP_PROXY` 需 `no_proxy='*'`。
