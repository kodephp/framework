# kode/process 调整 / 修改方向（交还上游维护方）

> 用途：把框架侧在压测中发现的 **kode/process 运行时缺陷** 整理成「具体改哪、怎么改、怎么验」的清晰指引，
> 便于上游直接动手。框架侧（kode framework）确认无辜、且 vendor 已 gitignore 无法以 patch 持久修复。
> 配套两份原始 bug 报告：`kode-process-native-concurrency.md`、`kode-process-swoole-regression.md`。

## 0. 总览

| # | 缺陷 | 严重度 | 影响运行时 | 当前状态 | 主改文件 |
|---|---|---|---|---|---|
| **F1** | 并发下监听 socket 停止 accept（连接被全面拒绝） | 🔴 高 | **Native（包默认运行时）** | 需修（方向明确） | `Runtime/Driver/NativeRuntime.php` `accept()` |
| **F2** | 并发 keep-alive 下 worker 静默崩溃重启 | 🔴 高 | **Swoole** | 需修（待 C 层 trace） | `Runtime/Driver/SwooleConnection.php` `sendResponse()` + `SwooleRuntime` |

> 共同影响：两个 bug 都让 `Kode::serve()` 在并发场景下**不可用**——F1 让默认（Native）运行时连不上，F2 让 Swoole 运行时崩溃。
> 目前**只有 Workerman 驱动在并发下健康**，框架侧已显式规避（压测/生产标定用 `KODE_RUNTIME=workerman`）。

---

## F1：Native 驱动并发 accept 缺陷

### 1.1 已确认事实（实测，非推测）

| 观察项 | 结果 |
|---|---|
| 单连接（curl / 串行 wrk） | 正常，`HTTP 200` |
| 并发 ≥ ~10（wrk `-c10` 起） | `Socket errors: connect N`，几乎全部连接被拒绝 |
| **workers=1（单 worker 独占监听 socket）** | **同样复现**（c50 时 connect 48/50）→ **排除「多 worker 共享 socket 惊群」假设** |
| worker 进程是否存活 | **存活**（压测后仍 1 个进程，`pgrep` 可见），并非崩溃退出 |
| PHP 错误日志 | **完全为空**（`display_errors=1` + `error_log` + `log_errors=1` 复测）→ worker 静默停止 accept，无异常 |
| 同条件 Workerman 驱动 | 170k+ rps、connect=0 → 证明是 Native 驱动自身问题，非机器/框架问题 |

> 结论：**问题在单 worker 的「监听 socket 可读事件 → accept」链路**，与多进程共享 socket、与业务 handler 都无关。

### 1.2 根因方向（高置信）

`NativeRuntime::accept()` 当前**每次可读事件只 `stream_socket_accept` 一次就返回**（见源码 L827-860）：

```php
private function accept($serverSock, array $listener): void
{
    $connSock = @stream_socket_accept($serverSock, 0, $peerName);
    if ($connSock === false) {
        return; // 惊群：其它 worker 已抢先 accept
    }
    // ...建连 + 注册 onReadable...
}
```

macOS 下（无 `ext-event`/`ext-ev` 时走 `SelectLoop` 兜底）存在一个已知行为：
**监听套接字的 readable 事件在「一波连接突发到达」时只上报一次**。若此时只 accept 一个，
队列里其余已排队的连接不会被 `select()` 再次上报为 readable（除非再有**新** SYN 到达）。
→ burst 中剩余连接被「搁置」，客户端 `connect()` 超时/被拒。

对照 Workerman / Swoole：**两者都在 readable 回调里 `while` 循环 accept 直到 EAGAIN**，
把整波 burst 一次性排空，因此在 macOS 上正常。kode Native 只 accept 一次，正是差异点。

### 1.3 具体改法方向 ✅

**文件**：`vendor/kode/process/src/Runtime/Driver/NativeRuntime.php`
**方法**：`accept()`（约 L827-860）
**改法**：把单次 `stream_socket_accept` 改为**循环 drain 直到返回 false（=队列空/EAGAIN）**，
把现有「建连 + 注册」逻辑抽成内部方法在循环内复用：

```php
private function accept($serverSock, array $listener): void
{
    do {
        $connSock = @stream_socket_accept($serverSock, 0, $peerName);
        if ($connSock === false) {
            break; // 已完成连接队列已空（EAGAIN），正常退出
        }
        $this->registerAcceptedConnection($connSock, $listener, $peerName ?? '');
    } while (true);
}

private function registerAcceptedConnection($connSock, array $listener, string $peerName): void
{
    $this->tuneSocket($connSock);
    $scheme = (string)$listener['scheme'];
    $conn   = new NativeConnection(
        $connSock, $peerName, $this->protocolClassFor($scheme),
        $this->loop, null, $this->maxSendBuffer
    );
    $isSsl = $scheme === 'ssl' || isset($listener['options']['ssl']);
    if ($isSsl) { $conn->setSslPending(); }
    $this->connections[$conn->id()]       = $conn;
    $this->connectionSockets[$conn->id()] = $connSock;
    $this->loop?->onReadable($connSock, fn($s) => $this->handleClientRead($s, $conn, $listener));
    if (!$isSsl) { $this->fire('connect', $conn); }
}
```

> 注：`stream_socket_accept(..., 0, ...)` 的 `0` 超时保证非阻塞；循环在队列空时 `false` 退出，
> CPU 不会空转。监听 socket 的 `onReadable` 注册本身不变（仍在 `runWorker()` L681-683）。

**为什么有效**：无论 `select()` 对监听 socket 的 ready 上报是 level 还是 edge 语义，
循环 drain 都能在一次回调里把整波突发全部 accept，从根本上消除「已排队连接被搁置」。
这与 Workerman/Swoole 的成熟范式一致，最小侵入。

**辅助加固（可选，非必须）**：若个别平台仍偶发 select 不重报监听可读，可在 `SelectLoop::select()` 层
对「监听类 fd」做 sticky re-check；但**首选改 `accept()` 循环**，不必动事件循环。

### 1.4 验证

- macOS：`wrk -t4 -c50 -d6s` 与 `-c200` 下 `Socket errors: connect` 应为 **0**，rps 应接近同条件 Workerman（~170k）。
- 回归测试：建议上游在 **macOS CI** 补 `c50`/`c200` 并发用例，connect 错误率 > 0 即红灯。
- Linux：`SO_REUSEPORT=true` 每 worker 独立 socket，本就正常；修复后需确认未引入回归（c200 仍健康）。

---

## F2：Swoole 驱动并发 keep-alive 静默崩溃

### 2.1 已确认事实

| 观察项 | 结果 |
|---|---|
| 触发 | `wrk -t8 -c200 -d8s`，Swoole 驱动（默认 `runtime=auto`）→ 吞吐塌至 ~2k rps、`Socket errors: connect 200` |
| 日志 | server log **无任何 PHP Fatal / Swoole 错误**（进程直接消失后由 manager 拉起）→ **C 层 segfault 特征** |
| 单 keep-alive 串发 300 次 `/ping` | **全部 200 OK** → 仅「多连接并发」触发，非单请求逻辑问题 |
| 20+ 对照排除 | 绕过 `sendResponse`、最小静态响应、关 gzip、改 `status` 参数、去 `responded` 守卫、`SWOOLE_PROCESS` 模式、关协程、handler `try/catch` 记录 `Throwable` —— **全部仍崩，且无任何异常记录** |
| 同 commit 仅切 Workerman | 174k+ rps、connect=0 → **回归仅限 Swoole 驱动** |

### 2.2 根因方向（待 C 层 trace 最终确认）

代码位置：`vendor/kode/process/src/Runtime/Driver/SwooleConnection.php` `sendResponse()`（L111-146）+

```php
public function sendResponse(ResponseInterface $response, string $protocol = '1.1'): void
{
    if ($this->response === null) { $this->send(...); return; }
    if ($this->responded) { return; }          // 仅防同请求内双写
    // ... $this->response->status() / header() / end($body) ...
    $this->responded = true;
    $this->response->end($body);
}
```

三个怀疑点（按概率排序）：

1. **BASE 模式 + 连接对象复用**：`SwooleRuntime` 默认 `SWOOLE_BASE` + `enable_coroutine=false`，
   每 worker 同步串行处理。并发多 keep-alive 下，BASE 模式的请求派发路径与
   `SwooleConnection::sendResponse`（`status()`+`header()`+`end($body)`+`responded` 守卫）存在并发交互问题。
2. **stale 响应对象**：现有 `responded` 守卫**只防「同一次请求内双写」**，不防「跨请求复用时访问已释放的
   `Swoole\Http\Response` C 对象」。若某 fd 的连接对象被复用而其底层 C 响应对象已被 Swoole 释放，
   再次 `end()` 即 segfault。
3. **5.2.31 自身改动**：若 5.2.31 相对上一可用版本在 `SwooleConnection`/`SwooleRuntime` 改过响应写出 /
   `responded` 守卫 / BASE 模式连接复用逻辑，优先 `git diff` 该区域。

### 2.3 具体改法方向 ✅

**第一步：先取证（必须）**——segfault 在 C 层，PHP 层看不到，需拿崩溃栈：

```php
// 启动前
ini_set('swoole.log_level', (string)SWOOLE_LOG_TRACE);  // 或 \Swoole\Coroutine::set(['log_level'=>...])
// shell: ulimit -c unlimited   （允许 core dump）
// 崩溃后：gdb <php> <core>  →  bt  取具体 C 符号（定位是 end()/header()/status() 还是 fd 管理）
```

**第二步：按 trace 结论二选一修复**

- **若根因是 kode/process 误用（跨请求 stale 响应对象）**：
  在每次请求开始处理时，**重置 `$responded = false` 并校验底层对象仍有效**：
  ```php
  // 请求入口（SwooleRuntime 派发处）重建/复用连接对象前：
  if (!$this->server->exist($this->fd)) { return; }  // fd 已失效则跳过
  $this->responded = false;                            // 每个新请求重置
  ```
  同时把 `responded` 守卫的语义从「同请求双写保护」扩展为「跨请求 stale 保护」。

- **若根因是 Swoole 6.2.2 自身 bug**：
  - 升级/回滚 `ext-swoole` 到无回归版本；或
  - kode/process 侧在 BASE 模式下改用 `SWOOLE_PROCESS` 模式规避（raw peer 用 PROCESS 模式正常，见回归报告对照）。

**第三步：加守卫防回归**——在 `sendResponse`/`send` 的 `end()` 调用前，
用 `method_exists($this->server,'exist') && $this->server->exist($this->fd)` 兜底校验 fd 存活，
避免对任意已失效连接调用 C 层方法。

### 2.4 验证

- `wrk -t8 -c200 -d8s` 在 Swoole 驱动下 `connect=0`、rps 回到 ~150k+（与 Workerman 同量级）；
- 单 keep-alive 串发 300 次仍全 200；
- 上游补 `c200` 并发用例进 CI，Swoole 驱动 connect 错误率 > 0 即红灯。

---

## 3. 包层面通用建议

1. **三运行时并发压测必须进 CI**：macOS 用 `c50`、Linux 用 `c200`；任一运行时 connect 错误率 > 0 即红灯。
   当前 F1/F2 都是「单连接正常、并发才暴露」，纯单连接测试发现不了。
2. **Native 驱动修复前不应作为默认生产运行时**：当前 `RuntimeType::Native` 是包默认，但 macOS 下并发不可用。
   修复 F1 前，建议框架/文档明确「`KODE_RUNTIME=workerman` 为推荐路径」（框架侧已规避）。
3. **连接对象生命周期守卫要覆盖「跨请求 stale」**，不仅是「同请求双写」——F2 的根因方向即在此。
4. **事件循环兜底（SelectLoop）对监听 socket 的语义需与 ext-event/ext-ev 对齐**：
   macOS 无 C 扩展时走 `stream_select`，其监听 socket 的 ready 上报语义与 Workerman 的 accept 循环范式不一致，
   已在 F1 给出最小侵入修法。

---

## 4. 复现脚本（框架仓库，已就绪）

| 用途 | 脚本 |
|---|---|
| Native vs Workerman 同等条件对比（含 F1 复现） | `benchmarks/peers/run_native_vs_workerman.sh` |
| `Kode::serve` 真实路径入口（可切 `KODE_RUNTIME=native\|workerman\|swoole`） | `benchmarks/peers/kode_swoole_server.php` |
| Swoole 回归对照（F2 复现） | `benchmarks/peers/run_workerman_kode.sh`（仅 Workerman，健康基准） |

最小复现（F1，单 worker 即可）：

```bash
cd <framework-root>
pkill -f kode_swoole_server.php; sleep 1
BENCH_PORT=8097 BENCH_WORKERS=1 KODE_PROFILE=lean KODE_RUNTIME=native \
  no_proxy='*' NO_PROXY='*' php -d memory_limit=512M benchmarks/peers/kode_swoole_server.php &
sleep 5
curl -s --noproxy '*' -m 3 -o /dev/null -w "single: HTTP %{http_code}\n" http://127.0.0.1:8097/ping   # 200
no_proxy='*' NO_PROXY='*' wrk -t4 -c50 -d6s http://127.0.0.1:8097/ping   # connect 48 → 复现 F1
```
