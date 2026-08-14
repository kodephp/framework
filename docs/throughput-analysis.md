# 吞吐对比 · 瓶颈定位 · kode/http 优化方案

> 目标：回答三个问题——(1) 裸 PHP / webman / hyperf 的压测对比；(2) 瓶颈到底在哪；
> (3) 如果是 kode/http 的问题，具体怎么改。
> 所有数字均来自本机实测，并标注测量口径，避免跨机绝对值误读（详见 `benchmarks.md` 的方法论）。

---

## 一、压测对比数据

存在**两种口径**，必须分开看，否则会得出错误结论：

- **in-process resident（无 HTTP）**：一次 boot + 循环 `handle()`，隔离「框架每请求开销」，
  不含网络/服务器。用于纯框架对比（裸 PHP / kode / Slim 都这么测）。
- **真实 HTTP 常驻（端到端）**：起一个常驻服务器，用 `wrk` 打 loopback。含网络 + 服务器开销。
  用于测 webman / hyperf / kode-on-Workerman。

### 同机同引擎对照（Workerman，4 worker，loopback，wrk 8 线程 / 64 连接 / 10s）

这是**最公平**的对比：kode 全栈与 webman 原生跑在**完全相同的服务器引擎**上，只换框架处理器。

| 实现 | 口径 | 吞吐 (req/s) | 相对裸 PHP | 备注 |
|---|---|---:|---:|---|
| 裸 PHP（纯逻辑） | in-process | ~219,000 | 100% | 构造 + `json_encode` 下限 |
| Slim 4 | in-process | ~282,000 | 129% | 框架开销可忽略 |
| **kode/http 基础层** | in-process | ~164,000–173,000 | 75–79% | 仅路由+管道+RouteRunner+响应，0 框架中间件 |
| kode 全栈 | in-process | ~36,000 | 16.4% | 默认全栈企业中间件 |
| **webman（Workerman 原生）** | HTTP 常驻 | **189,094** | — | 默认 0 中间件，可变请求对象 |
| kode 最小（5 常驻中间件） | HTTP 常驻 | 109,009 | — | 同引擎，仅常驻中间件 |
| **kode 全栈** | HTTP 常驻 | **47,088** | — | 同引擎，默认全栈 |

> hyperf（Swoole）本地安装仍在拉取组件；TechEmpower Round 22 plaintext 量级为 **~100k–400k+
> req/s**（视配置），与 webman 同一数量级，印证「常驻内存 + 可变对象」才是高吞吐的关键。

### 最关键的结论（同引擎）

**kode 全栈在同一 Workerman 引擎上只有 webman 的 ~25%（47k vs 189k），差 4×。**
而 kode/http 基础层（164k in-process）本身与 webman（189k，且还含 HTTP 开销）几乎同量级。
→ **瓶颈不在 kode/http，而在框架默认挂载的全栈中间件。**

---

## 二、瓶颈到底是什么情况（诚实诊断）

### 2.1 kode/http 不是瓶颈

kode/http 基础层 = 164k–173k req/s（in-process），仅比 Slim（282k）慢 **1.6×**，且 ≈ webman
（189k，webman 还多了 HTTP 开销）。其开销来自 PSR-7 **不可变消息语义**：每次 `withAttribute` /
`withHeader` / `withQuery` 都**克隆整条消息**；`PipelineRunner` 每中间件 `new self` 递归一次。
这是 PSR-15/PSR-7 的标准实现，不是缺陷，但确实是次要开销（见第三节怎么改）。

### 2.2 真正的瓶颈：框架「默认全栈企业中间件」

kode 是 **batteries-included**——默认在每个请求上跑一整套企业中间件：

> RequestId · Exception · JsonErrorHandler · ConnectionCleanup · **Audit** ·
> Cors · SecurityHeaders · Trace · Metrics · Idempotency · Retry · CircuitBreaker ·
> RateLimit · Session · Locale · Versioning · JsonBody · Transaction …

而 **webman 默认 0 中间件**（只 `json_encode` + `send`）。这就是同引擎 4× 差距的根因。
这是**设计取舍（开箱即用企业能力）**，不是 bug——但要高吞吐热路径，代价就是这套栈。

### 2.3 框架税拆解（全栈 36k vs kode/http 基础 164k，差 ~22µs/请求）

稳定可复现的部分：

- **Context::run**（每请求隔离，~2.3µs）：仅在 Swoole/协程并发时需要；FPM/CLI 单请求下是纯开销。
- **常驻中间件**（RequestId ~0.9µs、Exception/JsonErrorHandler/Audit/ConnectionCleanup 多数对
  /ping 早退）：合计约 1–2µs。
- **PSR-7 不可变消息克隆**：每个 `with*` 克隆整条消息，随中间件数与 `with*` 调用数线性增长——
  这是 kode/http 与 webman（可变对象）的根本架构差异，也是框架税的主要来源。

### 2.4 一个必须讲清的诚实点：「内核 ≈ 全栈」

压测里「内核（最小中间件）」与「全栈」都约 16.4% / 36k，几乎一样。原因：**optional 中间件
（Cors/Security/Trace/…）对 `/ping` 早退（early-return）**，所以剥不剥它们 /ping 数字不变。
但在**同引擎真实 HTTP** 下，全栈（47k）比最小（109k）慢 **2.3×**——说明 optional 中间件在
真实 HTTP 路径上有真实成本（响应头克隆、序列化）。即：「内核=全栈」只适用于 /ping 这类被
`ignore_paths`/`skipPaths` 命中的探针，**不能 extrapolation 成「中间件栈无成本」**。

> 历史上「内核≈全栈」还曾因 `disableSnippet()` 的嵌套 gating 键写错（未命中
> `security.audit.enabled` 等嵌套键）而**虚假成立**——已在 v0.8.24 修正。

---

## 三、如果是 kode/http 的问题——具体怎么改（用户明确问的）

结论先行：**kode/http 基础层不是主瓶颈**（164k ≈ webman 189k），但它确有 **1.6× 于 Slim 的
次要开销**，来自 PSR-7 不可变克隆 + 递归管道。要缩小区隔，按以下方案改（均在 `vendor/kode/http`，
属独立包，需在该仓库提 PR，本框架侧只做薄胶水）：

### 方案 A（推荐，webman/hyperf 同款）：内部用可变请求/响应对象

- **现状**：`MiddlewarePipeline` / `PipelineRunner` 严格按 PSR-15 不可变语义，每中间件 `process()`
  里 `return $next->handle($request->withX(...))` 都克隆整条 `ServerRequest`（含全部 headers /
  attributes / query 数组拷贝）。
- **改法**：kode/http 内部维护一个**可变消息载体**（像 webman 的 `support\Request`、hyperf 的
  Swoole 请求），中间件直接改字段、不克隆；仅在真正需要不可变快照时（如并发、或对外暴露 PSR-7
  时）才 clone。洋葱模型是**顺序执行、无并发**，逐请求克隆毫无必要。
- **预期**：消除每中间件 O(N) 消息拷贝，base 从 ~164k 提升到接近 Slim(282k)/webman(189k) 量级。

### 方案 B：把递归 `PipelineRunner` 展平为预编译循环

- **现状**：`PipelineRunner::handle()` 每次 `$next = new self($middlewares, $finalHandler, $index+1)`，
  每中间件一次对象分配 + 一次递归调用。
- **改法**：在管道**构建期**（boot 一次）把中间件链拍平成闭包数组，`handle()` 运行期用
  `for ($i=0; $i<$n; $i++) $response = $middlewares[$i]->process($request, $next);` 的索引化循环
  + 一个预编译的 `next` 闭包，去掉每中间件 `new self` 分配。
- **预期**：减少每请求中间件游标分配（次要，但零风险）。

### 方案 C：静态路由 handler 直缓存

- `Router` 已有 `method path` 结果缓存（O(1) 命中）。可进一步把「静态路由 → 最终 handler 闭包」
  直接缓存，避免每请求 `match` 分发。属于锦上添花，优先级最低。

### 落地顺序

A > B > C。A 是架构级、收益最大；B 是零风险的小改；C 收益最小。

---

## 四、框架侧已做 / 可做的调优增强

### 本版已落地（v0.8.27）：审计日志离路径异步导出

与 v0.8.25 访问日志同范式——`AuditService::record()` 在热路径只做一次内存入队
（`AuditSink`，进程级静态队列），真实格式化 + 日志写入由响应后的 `shutdown` / 优雅停机钩子
批量执行，**绝不阻塞客户端响应**；`audit.async=false` 退化为同步，兼容审计强一致场景。
对被审计的**真实业务端点**移除同步日志写入（此前约 10µs/请求）；`/ping` 因在 `ignore_paths`
早退故中性。

### 框架侧下一步（建议，非本版）

1. **热路径全局 skip_paths**：让 `/ping`、`/health`、`/metrics` 跳过非必要中间件栈（已有
   `JsonBodyMiddleware` / `TransactionMiddleware` 的 `skipPaths` 先例，可推广为全局短路），
   是提升探针吞吐最直接的杠杆。
2. **中间件 no-op 路径避免 `with*` 克隆**：先判断是否需要改响应，再克隆，减少无谓消息拷贝。
3. **kode/http 方案 A/B**（第三节）在对应包落地后，框架侧自动受益。

---

## 五、一句话总结

kode 全栈比 webman 慢 4×，**不是 kode/http 的锅**（其基础层与 webman 同量级），而是框架默认
挂载的**全栈企业中间件**——这是「开箱即用」的设计代价。要提响应：热路径用 skip_paths 跳过
非必要栈（框架侧可立即做），并把 kode/http 的不可变消息克隆改为内部可变对象（对应包提 PR）。
