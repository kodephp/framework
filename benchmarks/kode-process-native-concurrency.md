# kode/process Native 驱动并发连接缺陷（上游 bug 报告）

> ✅ **状态：已在 kode/process 5.2.36 修复**（见 [`kode-process-fix-directions.md`](./kode-process-fix-directions.md) **F1**）。
> 5.2.36 的 `NativeRuntime::accept()` 已改为循环 drain 直到 EAGAIN（原文下方 §4 根因即此），复测 `workers=1` 单 worker 并发下（c50）
> 已正常服务（此前 5.2.31 同样条件 `connect 48/50`）；v0.8.41 三运行时压测中 kode·lean @ Native 达 166k/136k rps，与 Workerman/Swoole 同档。
> 本报告保留作回归证据。

- **组件**：`kode/process`（vendor，gitignore，非框架仓库）
- **运行时**：`RuntimeType::Native`（自研纯 PHP `pcntl`/`posix` master-worker + 可插拔事件循环）
- **环境**：macOS（Apple Silicon，11 核），PHP 8.3.33，无 `ext-event`/`ext-ev` → 事件循环走 `stream_select` 兜底
- **严重度**：🔴 高（并发下服务不可用，非性能降级而是连接被全面拒绝）——**5.2.36 已修复**
- **框架侧是否无辜**：是。缺陷在 kode/process 的 Native 驱动，与框架业务代码无关（同一份 `Kode::serve` 路径在 Workerman 驱动下健康）。
- **调整方向（具体改法）**：见 [`kode-process-fix-directions.md`](./kode-process-fix-directions.md) 的 **F1** 节。

## 1. 现象

`Kode::serve('http://127.0.0.1:8097', [...], 'native')` 启动后：

- **单连接**（curl / 串行 wrk）正常：`curl /ping` 稳定返回 `HTTP 200`，耗时 ~9ms。
- **任意并发 ≥ ~10** 立即全面失败：
  - `wrk -t2 -c10 -d5s` → `Socket errors: connect 10, read 16135`，`Requests/sec ≈ 3188`（仅首连接零星通过）。
  - `wrk -t8 -c200 -d8s` → `Socket errors: connect 200`，`Requests/sec ≈ 0.49`。
  - `wrk -t4 -c100` → `Socket errors: connect 100`，`Requests/sec ≈ 0.79`。
- worker **无 PHP fatal / 无异常日志**（已用 `php -d display_errors=1 -d error_log=...` 复测，日志为空）；worker 进程**静默停止接受新连接**，并非崩溃退出。

## 2. 最小复现

```bash
# 1) 启动（kode 框架 peer 服务器，强制 Native 驱动）
cd <framework-root>
pkill -f kode_swoole_server.php; sleep 1
BENCH_PORT=8097 BENCH_WORKERS=2 KODE_PROFILE=lean KODE_RUNTIME=native \
  no_proxy='*' NO_PROXY='*' \
  php -d memory_limit=512M -d display_errors=1 -d error_log=/tmp/native_err.log \
  benchmarks/peers/kode_swoole_server.php

# 2) 单连接（通过）
curl -s --noproxy '*' -m 3 -o /dev/null -w "HTTP %{http_code}\n" http://127.0.0.1:8097/ping   # => 200

# 3) 并发（失败）
no_proxy='*' NO_PROXY='*' wrk -t2 -c10 -d5s http://127.0.0.1:8097/ping
#    => Socket errors: connect 10, read 16135 ; Requests/sec ~ 3188

# 完整对照（webman 锚 + kode·lean/default × Workerman/Native）：
bash benchmarks/peers/run_native_vs_workerman.sh
```

## 3. 已排除项（对照表）

| # | 假设 | 验证方式 | 结论 |
|---|---|---|---|
| 1 | 监听器 backlog 太小 | `openServerSocket()` 已设 `backlog=1024` | 排除；connect 在首连接后即失败，与 backlog 无关 |
| 2 | worker 崩溃退出 | `error_log` + `ps` 观察，无 exit/segfault，**压测后进程仍存活** | 排除；worker 静默停 accept |
| 3 | HTTP/2 协商把 1.1 请求误判为 h2c | 客户端明确 HTTP/1.1；`wantsH2cUpgrade` 需 `Upgrade: h2c` 头才会升级 | 排除 |
| 4 | keep-alive 复用路径死锁 | 即便 `-c10` 短连接（每请求新 TCP）也 connect 失败 | 排除；首 TCP 握手就被拒 |
| 5 | 多 worker 共享监听 socket 惊群 | **`workers=1` 单 worker 独占监听 socket 下同样复现**（c50 → connect 48/50）→ 与共享 socket / 多进程无关 | **排除**；根因在单 worker 的 accept 路径（见 §4） |
| 6 | 框架 handler 抛异常打死 worker | Workerman 驱动同路径 157k 健康；错误被 `HttpBridge` try/catch 兜底 | 排除 |

## 4. 根因方向（已定位，高置信）

**`NativeRuntime::accept()` 每次可读事件只 `stream_socket_accept` 一次就返回**（源码约 L827-860）：

```php
$connSock = @stream_socket_accept($serverSock, 0, $peerName);
if ($connSock === false) { return; }
// ...建连 + 注册 onReadable...
```

macOS 下（无 `ext-event`/`ext-ev` 时走 `SelectLoop` 兜底）的已知行为：**监听套接字的 readable
事件在「一波连接突发到达」时只上报一次**。此时只 accept 一个，队列里其余已排队的连接**不会再被
`select()` 上报为 readable**（除非再有新 SYN 到达）→ burst 中剩余连接被「搁置」，客户端 `connect()`
超时/被拒。

对照 Workerman / Swoole：两者都在 readable 回调里 **`while` 循环 accept 直到 EAGAIN**，把整波
突发一次性排空，故在 macOS 正常。**kode Native 只 accept 一次正是差异点。**

> **关键佐证**：`workers=1`（单 worker 独占监听 socket，无跨进程共享）下仍复现 → 彻底排除
> 「共享 socket / 多 worker 惊群」，坐实是单 worker 的 accept 路径语义问题。

**具体修法**：把 `accept()` 改为循环 drain 直到 `stream_socket_accept` 返回 false（队列空/EAGAIN），
把现有「建连 + 注册 onReadable」逻辑抽成内部方法在循环内复用。完整代码见
[`kode-process-fix-directions.md`](./kode-process-fix-directions.md) **F1** 节。

## 5. 影响与建议处置

- **影响**：Native 驱动（kode/process 的**默认运行时**）在并发场景下不可用；框架 `Kode::serve()` 默认即 Native，意味着**未显式指定 `KODE_RUNTIME=workerman|swoole` 的部署在并发下会连接失败**。
- **框架侧无法持久修复**：vendor 已 gitignore，改动不入框架仓库，`composer update` 会覆盖。
- **临时规避（用户已拍板）**：压测/生产标定运行时显式用 `KODE_RUNTIME=workerman`（Swoole 驱动另有回归）；Native 仅作零依赖兜底、不进入性能对比基线。
- **上游修复建议**：按 [`kode-process-fix-directions.md`](./kode-process-fix-directions.md) **F1** 改 `accept()` 为循环 drain，并在 macOS 补 `c50`/`c200` 并发 CI 用例。
