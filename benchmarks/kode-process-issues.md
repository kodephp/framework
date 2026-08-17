# kode/process 运行时问题（统一记录 · 已在 5.2.36 修复）

> **当前状态（直接回答）：kode/process 已无未决运行时问题。**
> 当前锁定版本 `kode/process ~5.2.36`（`composer.json`），其中 **F1（Native 驱动并发拒绝连接）** 与
> **F2（Swoole 驱动并发 keep-alive 静默崩溃）** 两个并发缺陷均已修复，三运行时（Native / Swoole / Workerman）
> 在相同压测条件下全部可用且同档（见 `PEER_BENCHMARK.md` §2）。
> 本文件是这两处问题的**根因 + 修复 + 验证**留存，作回归证据与上游交接记录，框架侧不再需要任何临时规避。

---

## 0. 总览

| # | 缺陷 | 严重度 | 影响运行时 | 修复版本 | 主改文件 |
|---|---|---|---|---|---|
| **F1** | 并发下监听 socket 停止 accept（连接被全面拒绝） | 🔴 高 | **Native（包默认运行时）** | 5.2.36 | `Runtime/Driver/NativeRuntime.php` `accept()` |
| **F2** | 并发 keep-alive 下 worker 静默崩溃重启 | 🔴 高 | **Swoole** | 5.2.36 | `Runtime/Driver/SwooleRuntime.php` `onRequest()` + `SwooleConnection::reset()` |

> 共同影响：两个 bug 都让 `Kode::serve()` 在并发场景下不可用——F1 让默认（Native）运行时连不上，F2 让 Swoole 运行时崩溃。
> 5.2.36 修复后，**只有 Workerman 驱动在并发下健康**这一临时规避结论已作废，三运行时全部可用。

---

## F1：Native 驱动并发 accept 缺陷（已修复）

### 1.1 已确认事实（实测，非推测）

| 观察项 | 结果 |
|---|---|
| 单连接（curl / 串行 wrk） | 正常，`HTTP 200` |
| 并发 ≥ ~10（wrk `-c10` 起） | `Socket errors: connect N`，几乎全部连接被拒绝 |
| **workers=1（单 worker 独占监听 socket）** | **同样复现**（c50 时 connect 48/50）→ **排除「多 worker 共享 socket 惊群」假设** |
| worker 进程是否存活 | **存活**（压测后仍 1 个进程），并非崩溃退出 |
| PHP 错误日志 | **完全为空**（开 `display_errors` + `error_log`）→ worker 静默停止 accept，无异常 |
| 同条件 Workerman 驱动 | 170k+ rps、connect=0 → 证明是 Native 驱动自身问题 |

> 结论：**问题在单 worker 的「监听 socket 可读事件 → accept」链路**，与多进程共享 socket、与业务 handler 都无关。

### 1.2 根因

`NativeRuntime::accept()` 每次可读事件只 `stream_socket_accept` 一次就返回。macOS（无 `ext-event`/`ext-ev` 时走
`SelectLoop` 兜底）的监听套接字 readable 事件在「一波连接突发」时只上报一次；只 accept 一个，队列里其余已排队连接不会被
`select()` 再次上报，除非再有新 SYN 到达 → burst 剩余连接被搁置，客户端 `connect()` 超时/被拒。

对照 Workerman / Swoole：两者都在 readable 回调里 `while` 循环 accept 直到 EAGAIN，把整波突发一次性排空。

### 1.3 修复（5.2.36 已落地）

把单次 `stream_socket_accept` 改为**循环 drain 直到返回 false（队列空 / EAGAIN）**，建连逻辑抽成内部方法复用：

```php
private function accept($serverSock, array $listener): void
{
    do {
        $connSock = @stream_socket_accept($serverSock, 0, $peerName);
        if ($connSock === false) { break; }   // 队列空（EAGAIN），正常退出
        $this->registerAcceptedConnection($connSock, $listener, $peerName ?? '');
    } while (true);
}
```

### 1.4 验证

- macOS：`wrk -t4 -c50 -d6s` 与 `-c200` 下 `Socket errors: connect` = 0，rps 回到 ~170k（与 Workerman 同档）。
- `workers=1` 单 worker 并发（c50）正常服务（此前 5.2.31 同样条件 `connect 48/50`）。
- v0.8.41 三运行时压测：kode·lean @ Native = 166k/136k rps，与 @Workerman/@Swoole 同档。

---

## F2：Swoole 驱动并发 keep-alive 静默崩溃（已修复）

### 2.1 已确认事实

| 观察项 | 结果 |
|---|---|
| 触发 | `wrk -t8 -c200 -d8s`，Swoole 驱动 → 吞吐塌至 ~2k rps、`Socket errors: connect 200` |
| 日志 | server log **无任何 PHP Fatal / Swoole 错误**（进程直接消失后由 manager 拉起）→ **C 层 segfault 特征** |
| 单 keep-alive 串发 300 次 `/ping` | **全部 200 OK** → 仅「多连接并发」触发 |
| 20+ 对照排除 | 绕过 `sendResponse`、最小静态响应、关 gzip、改 `status` 参数、去 `responded` 守卫、`SWOOLE_PROCESS` 模式、关协程、handler `try/catch` 记录 `Throwable` —— **全部仍崩，无异常记录** |
| 同 commit 仅切 Workerman | 174k+ rps、connect=0 → **回归仅限 Swoole 驱动** |

### 2.2 根因

`SwooleConnection::sendResponse()` 的 `responded` 守卫**只防「同一次请求内双写」**，不防「跨请求复用时访问已释放的
`Swoole\Http\Response` C 对象」。并发多 keep-alive 下，某 fd 的连接对象被复用而其底层 C 响应对象已被 Swoole 释放，
再次 `end()` 即 segfault。`SwooleRuntime` 默认 `SWOOLE_BASE` + `enable_coroutine=false`，每 worker 同步串行处理，
与 `sendResponse` 的并发交互放大了该问题。

### 2.3 修复（5.2.36 已落地）

`SwooleRuntime::onRequest()` 在**每个新请求**调用 `$conn->reset()`，重置 `$responded` 守卫并清掉上一请求的 stale
响应对象引用，把语义从「同请求双写保护」扩展为「跨请求 stale 保护」：

```php
// SwooleRuntime::onRequest() 派发处
$conn->reset();   // 重置 $responded + 释放上一请求 stale 响应对象
```

### 2.4 验证

- `wrk -t8 -c200 -d8s` 在 Swoole 驱动下 `connect=0`、rps 回到 ~161k（与 Workerman 同档）、worker 不再静默重启。
- 单 keep-alive 串发 300 次仍全 200。
- v0.8.41 三运行时压测：kode·lean @ Swoole = 161k/130k rps，与 @Native/@Workerman 同档。

---

## 3. 给上游的通用建议（避免再犯）

1. **三运行时并发压测必须进 CI**：macOS 用 `c50`、Linux 用 `c200`；任一运行时 connect 错误率 > 0 即红灯。
   当前 F1/F2 都是「单连接正常、并发才暴露」，纯单连接测试发现不了。
2. **连接对象生命周期守卫要覆盖「跨请求 stale」**，不仅是「同请求双写」——F2 根因即在此。
3. **事件循环兜底（SelectLoop）对监听 socket 的语义需与 ext-event/ext-ev 对齐**：macOS 无 C 扩展时走 `stream_select`，
   其监听 socket 的 ready 上报语义需配 `accept()` 循环 drain（F1 已证）。

## 4. 复现脚本（框架仓库）

- `benchmarks/peers/run.sh`：统一入口，跑 引擎天花板（swoole_raw / workerman_raw）+ webman + hyperf + 本框架三运行时（native/swoole/workerman）。
- `benchmarks/peers/kode_swoole_server.php`：`Kode::serve` 真实路径（`KODE_PROFILE=lean|default`、`KODE_RUNTIME=native|swoole|workerman`）。

最小复现（F1，单 worker 即可，5.2.31 时代）：

```bash
cd <framework-root>
pkill -f kode_swoole_server.php; sleep 1
BENCH_PORT=8097 BENCH_WORKERS=1 KODE_PROFILE=lean KODE_RUNTIME=native \
  no_proxy='*' NO_PROXY='*' php -d memory_limit=512M benchmarks/peers/kode_swoole_server.php &
sleep 5
curl -s --noproxy '*' -m 3 -o /dev/null -w "single: HTTP %{http_code}\n" http://127.0.0.1:8097/ping   # 200
no_proxy='*' NO_PROXY='*' wrk -t4 -c50 -d6s http://127.0.0.1:8097/ping   # 5.2.31: connect 48 → 复现；5.2.36: 正常
```
