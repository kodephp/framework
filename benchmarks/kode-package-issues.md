# kode 包问题说明（供包侧修复）

> 本文档由 framework 压测侧整理，基于 `benchmarks/peers/bench_db_pooled.sh` 公平横比与
> `kode@Native full-vs-lean A/B`（见 `PEER_BENCHMARK.md` §4.3）。所有数字均在本机实测，
> 跨机不可横比，**横比只看比值**。目的：把「composer 中 kode 包的问题」写清楚，便于在对应包侧修复。
>
> 测试环境：PHP 8.3.33，kode/process 5.2.36，kode/http 3.4.2，kode/database 1.15.5，
> kode/fibers 4.10.0；Apple Silicon（热降频 ±2~3×，故只取稳健比值与 DB 完整性 1:1 信号）。
> 本地 DB：MySQL `root/root`、MariaDB `root/root`、PostgreSQL `root` 无密码（库 `kode_bench`）。
>
> **状态（2026-08-23 · framework v0.8.44）**：
> - **A. kode/http**：§3.5/§3.6 卫生项已由上游 **3.4.6** 合入（`json()` rawBody 快速路径、`getBody()` 非破坏性），
>   上游 3.4.6 另自带 `LazyServerRequest`/`LazyUri`（热路径免 header 规范化，与框架侧实现同方向）；
>   但「无 DB 热路径 2.6–2.9×」的**主因（PSR-7 对象分配/GC 基线）未消除**，仍需 P5 lean opt-out 或包侧
>   「热路径零分配 Request」继续推进（见下文 A 的「推荐修复方向」与 `PEER_BENCHMARK.md` §7）。详见
>   `docs/kode-http-change-spec.md`（状态块）。
> - **B. kode/database**：缺陷 1、2 **已由上游 1.15.6** 修复（`PoolManager::init` 运行时感知自动降级
>   ProcessPool；新增 `ScopedConnection` RAII 自动归还）。框架 v0.8.44 尚未切换到 kode/database 原生池
>   （框架级 `src/Database/ConnectionPool` 仍可用、诚实）；切换属后续选型决策，见下方 B 的「推荐修复方向」。

---

## A. kode/http —— 无 DB 端点每请求吞吐 ~2.6–2.9× 劣于 webman/hyperf

### 症状

在**无 DB** 端点（hello-world `/ping`、内存 JSON `/bench/json`）上，kode@Native 走完整 PSR-7 路径
（`KODE_PROFILE=off`，即零跨切面中间件，仅内核路由派发）吞吐远低于 webman / hyperf：

| peer | 运行时 | /ping | /bench/json |
| --- | --- | ---: | ---: |
| **webman** | Workerman | 168,983 | 180,777 |
| **hyperf** | Swoole | 93,242 | 107,404 |
| **kode@Native（full 路径）** | kode/process Native | 52,149 | 50,379 |

kode 仅为 webman 的 **31% / 28%**、hyperf 的 **56% / 47%**，且无 DB、零中间件——理论上不该有此差距。

### 证据：full-vs-lean A/B（本会话 2026-08-22 实测，同 Native 4 进程、背靠背）

| 端点 | full 路径 | lean 旁路（`KODE_LEAN=1`，跳过 `toPsr7`+`App::handle`+`emit` 直发 raw） | 比值 |
| --- | ---: | ---: | ---: |
| /ping | 74,676 | 191,309 | **2.56×** |
| /bench/json | 65,189 | 189,623 | **2.91×** |

> lean 旁路（191k/190k）**超过** webman（169k/181k）——证明 kode 内核与运行时本身不慢，
> 慢的 100% 来自「完整 PSR-7 路径」（`HttpBridge::toPsr7` → `kode/http App::handle` → `HttpBridge::emit`）。
> 该 lean 旁路已作为 `P5 lean opt-out` 落地于 framework（`src/Server/HttpServer.php`），生产路径实测
> kode json 默认 117k → lean 187k（≈ webman 持平）。但 lean 跳过的是**全部中间件**，真实业务不能长期关。

### 根因（定位结果，供包侧确认）

框架侧已做的优化（**不是根因**，已排除）：
- `HttpBridge::toPsr7()` 用 `LazyServerRequest`，仅 4 个重 getter 首次访问才解析（lazy），路由匹配只消费 method+path，对 `/bench/json` 这类 handler 0 解析开销 → 框架桥接层无剩余空间。
- `HttpBridge::emit()` 走 `toRaw` → `getBodyString()` 零拷贝 + `send(...,true)`，仅自动 gzip 才退回官方 `sendResponse`。
- `vendor/kode/http` 已落 `StringStream`（小体直接内存持有）+ `Resp::json()` 走 `Response::make` 持有原生字符串体（3.4.2 验证）。

**真正的成本在 `kode/http` 包内**（`vendor/kode/http/src/App.php::handle` → `MiddlewareDispatcher` →
`MiddlewarePipeline` 预编译闭包链派发 + 每请求 PSR-7 `ServerRequest`/`Response` 不可变对象构造）：
1. 每请求都从原生 `ProcessRequest` 构造一个完整 PSR-7 `ServerRequest` 对象图（即便路由只消费 method+path）。
   webman 直接操作原生 request 对象、不重建 PSR-7；hyperf 也走 PSR-7 但构造更轻（故 hyperf 仍达 93–113k，是 kode 的 1.6–2.4×）。
2. 响应经 `Response` 构造 + Emitter 写出；即便 framework 已走 rawBody 快路径，`kode/http` 的 `App::handle`
   返回前仍完成 PSR-7 `Response` 物化。
3. 每请求不可变对象分配 + 对应 GC 压力：小体端点（`/ping` 15B）即已落后 3×，说明开销与体大小无关，
   纯属「对象分配/GC」——微基准已证派发链路与体大小无关（2KB 预构建 6.47µs ≈ 小响应 6.49µs）。

### 推荐修复方向（包侧）

1. **热路径零分配 Request**：`kode/http` 提供「轻量请求视图」——路由只取 method+path+headers 时，
   不构造完整 PSR-7 attribute/cookie/file/query/body 图（现有 `LazyServerRequest` 已 defer body/query/cookie/files，
   但不可变 `ServerRequest` 基类 + `with*` 克隆仍每请求分配；需进一步让热路径根本不进入不可变构造）。
2. **raw 响应快路径下沉到包**：`App::handle` / `Response::resolve` 在「响应已是预构建字符串 + status + headers」时，
   允许 runtime 直接 `send(raw)` 而不经 PSR-7 Stream 物化（framework 的 `toRaw` 已证明这条路径 ≈ webman 持平）。
3. **对标 hyperf 的 PSR-7 构造开销做 profiling**：hyperf 同走 PSR-7 却快 1.6–2.4×，定位 `kode/http` 具体多分配了什么
   （猜测：`ServerRequest` 构造时的冗余 header 规范化 / 属性拷贝 / `Uri` 对象全量构建）。
4. 优先级：**P0（性能正确性）**。这是 kode「无中间件热路径」相对 webman 落后 3× 的唯一系统原因，
   lean opt-out 只是框架侧止血，根治在 `kode/http`。

---

## B. kode/database —— 连接池在 kode/process Native（非 Swoole）运行时不可用

### 症状

framework 公平横比（`/bench/db`、一次主键 SELECT→JSON）**无法使用 kode/database 的连接池**，
被迫回退到「非池化 per-worker PDO」（同一 worker 内 `connectionCache` 复用一根连接）。
webman（自研有界 PDO 池）、hyperf（`hyperf/database` 协程池）均用生产级池且 0 错误、DB 完整性 1:1。

### 根因（两处缺陷，已在 1.15.5 验证）

**缺陷 1：`PoolManager::init` 默认 `poolType='connection'` = Swoole `Coroutine\Channel`，非 Swoole 运行时构造即 Fatal。**

- `vendor/kode/database/src/Db/Db.php:148` `Db::addConnection($config)` → `PoolManager::init($config, $name)`，
  未传 `poolType`，落到 `PoolManager.php:28` 默认 `'connection'`。
- `PoolManager.php:33` `match ($poolType)` → `'connection' => new ConnectionPool(...)`（`ConnectionPool` 内部用 Swoole `Coroutine\Channel`）。
- 在 kode/process **Native** 或 **Workerman** 运行时（非协程）构造 `Coroutine\Channel` → **Fatal**，server 起不来。
- 即便显式 `'fiber'`：`FiberPool`（`PoolManager.php:36`）的 `createConnector` 默认 `LaravelConnector`，
  委托 `illuminate\Capsule`——framework 未为 `kode-mysql` 初始化 Capsule → connector 为 `null` 报错。

**缺陷 2：`FiberPool` 需手动 `release()`，而 `QueryBuilder` 不释放 → 并发「连接池已满」500。**

- `FiberPool`（`src/Pool/FiberPool.php`）按 max 借连接，要求每次查询后显式 `release()`。
- `QueryBuilder` / `Connection`（`Connection.php:487` `PoolManager::getConnection`）取连接后**不自动释放**，
  每请求新 fiber 占一连接、达 `max` 即满，高并发全 500。
- 对比：webman/hyperf 的池在「请求/协程结束」自动归还连接，无需业务手动 `release`。

### 影响

- kode/database 的池特性在「多进程同步运行时（Native/Workerman）」下**实际不可用**：默认 Swoole Channel 直接 Fatal，
  切 fiber 又因不自动归还而 500。
- framework 当前用「每 worker 一根非池化 PDO」正确且 0 错误（同步运行时生产实践），但意味着**池特性在本场景是死重**。
- framework 的 `Kode\Framework\Database\ConnectionPool`（有界 PDO 池 + `closeCursor`）是框架级 workaround，可用、诚实；
  但应由 `kode/database` 原生提供等价的多进程安全池来替代。

### 推荐修复方向（包侧）

1. **`PoolManager::init` 运行时感知**：非 Swoole 运行时默认走「进程安全池」（per-worker 连接缓存，等价于 framework 现有
   `ConnectionPool`），而非 Swoole `Coroutine\Channel`；或在非协程环境请求 `'connection'` 池时抛清晰错误而非 Fatal。
2. **自动归还连接（RAII）**：新增/修正一个「进程池」，在查询/请求作用域结束自动 `release()`，使 `QueryBuilder` 无需手动
   `release`——对齐 webman/hyperf 行为。
3. **文档补全**：给出「多进程（非 Swoole）部署」的正确连接配置示例（当前 README 只覆盖 connection/process/parallel/fiber，
   未说明多进程同步运行时的推荐池型）。
4. 优先级：**P1**。不阻塞无池化部署（framework 已规避），但使 kode/database 池在主流多进程运行时可用，消除框架级 workaround。

---

## 附：本会话实测数据出处

- 公平双库横比汇总：`/tmp/fair_full_run.log`（2026-08-22 09:28，bench_db_pooled.sh）
- kode full-vs-lean A/B：`/tmp/kode_ab_0.log` + `/tmp/kode_ab_1.log`（2026-08-22 本会话）
- 历史调优轨迹与 P5 lean opt-out 落地：`PEER_BENCHMARK.md` §6 / §7 / §8
