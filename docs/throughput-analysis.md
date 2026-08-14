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

> ⚠️ **本表为 kode/http 3.4.0 之前（不可变消息时代）的实测**。kode/http **3.4.0 已落地两处关键优化**：
> （1）**消息对象改为可变**（仿 webman/hyperf，`withHeader`/`withAttribute` 直接改自身返回 `$this`，
> 不再克隆整条消息）——即下方「方案 A」；（2）**中间件管道预编译为闭包链复用**——即「方案 B」。
> 二者共同作用后，「每请求 PSR-7 不可变克隆」这一历史主瓶颈已**消除**。in-process 同口径实测：
> 全栈业务端点占裸 PHP 比例由 3.3.0 的 **9.0% → 3.4.0 的 13.4%（约 +48% 相对增益）**，且同等条件下
> 全栈相对最小内核的「框架税」≈ 0%（审计税 ≈ 0%）。下方第二节瓶颈结论需按 3.4.0 重新解读。

> ⚠️ **测量口径重大修正（覆盖上方「框架税≈0%」旧结论）**：上方「框架税≈0%」是**进程内污染伪影**——
> 旧压测在**单进程**里「重场景紧跟轻场景」测量，触发 CPU 调频 / 热节流污染，使轻场景（内核 /ping）数字
> 从真实的 ~155k 被拖到 ~40k，与全栈 /ping 落入同一地板，故比值≈1、框架税「看似 0%」。这是测量顺序
> 假象，**非框架事实**。现已改为**每个场景独立 PHP 进程测量（进程隔离）**，数字稳定收敛：内核 /ping ≈ 155k
> （占裸 PHP ~68%）、全栈业务端点 /bench/json ≈ 31k（~14%）。**诚实框架税（全栈业务端点 ÷ 内核同端点）
> ≈ 57%**——即默认 13 个企业中间件栈相对最小内核的真实吞吐损耗；审计税（审计开 ÷ 关，同端点）≈ 0%
> （离路径异步导出生效）。下方结论均已按此修正。

### 最关键的结论（同引擎）

**kode 全栈在同一 Workerman 引擎上只有 webman 的 ~25%（47k vs 189k），差 4×。**
而 kode/http 基础层（164k in-process）本身与 webman（189k，且还含 HTTP 开销）几乎同量级。
→ **瓶颈不在 kode/http，而在框架默认挂载的全栈中间件。**

---

## 二、瓶颈到底是什么情况（诚实诊断）

### 2.1 kode/http 不是瓶颈

kode/http 基础层 = 164k–173k req/s（in-process，3.4.0 之前），仅比 Slim（282k）慢 **1.6×**，
且 ≈ webman（189k，webman 还多了 HTTP 开销）。其（当时）开销来自 PSR-7 **不可变消息语义**：每次
`withAttribute` / `withHeader` / `withQuery` 都**克隆整条消息**；`PipelineRunner` 每中间件 `new self`
递归一次。**这两点均已在 kode/http 3.4.0 消除**（可变消息 + 管道预编译，见第三节方案 A/B），故该段
描述的是历史基线，3.4.0 后 kode/http 基础层应显著更高。

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
- **常驻中间件**（RequestId / Exception / JsonErrorHandler / Audit / ConnectionCleanup 多数对
  /ping 早退）：合计约 1–2µs。
- **PSR-7 消息克隆（⚠️ 3.4.0 已消除）**：3.4.0 之前，每个 `with*` 克隆整条消息，随中间件数与
  `with*` 调用数线性增长——是 kode/http 与 webman（可变对象）的根本架构差异，也是当时框架税的主要来源。
  **3.4.0 起消息对象可变，`with*` 不再克隆**，该项成本归零（见第三节方案 A/B 已落地）。
- **kode/http 分发 / 路由 / DI 内核（3.4.0 后的新前沿）**：可变消息消除克隆后，in-process 下**纯分发内核**
  （仅路由 + handler + 响应、去掉响应构建）已与 Slim 持平（~3.5µs）；差距主要在 `Response::json` 构造
  （占单次 handle ~20%）与 3 个常开安全/可观测中间件的间接调用，而非「全栈中间件栈」。这是「继续提升
  框架数据」在 3.4.0 之后的真实着力点（见第三节方案 D 的 StringStream 实测 +16% 内核）。

### 2.4 一个必须讲清的诚实点：「内核」与「全栈」的真实差距（进程隔离后可见）

旧压测里「内核（最小中间件）」与「全栈」都约 16.4% / 36k，几乎一样——**这是进程内污染伪影**：
同进程「重场景紧跟轻场景」把内核数字从真实的 ~155k 拖到 ~40k，与全栈 /ping 落入同一地板。进程隔离修正后，
二者真实差距巨大：**内核 /ping ≈ 155k（基线 68%），全栈业务端点 /bench/json ≈ 31k（基线 14%），框架税 ≈ 57%**。
原因：默认 13 个企业中间件（Cors/Security/限流/熔断/重试/幂等/会话/特性/审计…）在业务端点真实执行，
其开销无法被 `ignore_paths`/`skipPaths` 掩盖。**「内核=全栈」在污染态下虚假成立，不能作为「中间件栈无成本」的依据**——
进程隔离后，中间件栈的真实成本（≈57%）清晰可见，也正是继续提响应的主杠杆。

> 历史上「内核≈全栈」还曾因 `disableSnippet()` 的嵌套 gating 键写错（未命中
> `security.audit.enabled` 等嵌套键）而**虚假成立**——已在 v0.8.24 修正。

---

## 三、如果是 kode/http 的问题——具体怎么改（用户明确问的）

结论先行：**kode/http 基础层不是主瓶颈**（3.4.0 之前 164k ≈ webman 189k），但它确有 **1.6× 于
Slim 的次要开销**，来自 PSR-7 不可变克隆 + 递归管道（均为 3.4.0 之前的状态）。**这两点已在
kode/http 3.4.0 修复**（见下方方案 A/B「✅ 已落地」），框架侧经 `composer update` 自动受益，无需
在框架仓库改动：

### 方案 A（推荐，webman/hyperf 同款）：内部用可变请求/响应对象 —— ✅ 已落地 kode/http 3.4.0

- **现状（3.4.0 之前）**：`MiddlewarePipeline` / `PipelineRunner` 严格按 PSR-15 不可变语义，每中间件
  `process()` 里 `return $next->handle($request->withX(...))` 都克隆整条 `ServerRequest`（含全部
  headers / attributes / query 数组拷贝）。
- **✅ 已落地（kode/http 3.4.0）**：`Psr7\Message\Response::withHeader` / `ServerRequest::withAttribute`
  等**全部改为原地修改自身并返回 `$this`，不再克隆**（注释明确「自 v3.4 起，消息对象为可变，仿
  webman/hyperf」）。洋葱模型顺序执行、无并发，逐请求克隆确无必要——这正是当初判定「收益最大」的
  架构级修复，现已合入用户最新发布的 3.4.0。框架侧**零改动**，经 `composer update kode/http` 自动受益。
- **预期（已验证）**：消除每中间件 O(N) 消息拷贝。in-process 同口径实测全栈业务端点占裸 PHP 比例
  **9.0% → 13.4%（约 +48% 相对增益）**；同等条件下审计税 ≈ 0%（离路径异步导出生效）。**注：全栈相对
  内核的「框架税」在进程隔离修正后实测 ≈ 57%**（全栈 13 个企业中间件栈的真实成本），旧「框架税≈0%」为
  进程内污染伪影，详见首节方法论修正注记。
- **衍生**：可变消息后，单独的「批量 `withHeaders`」已无必要（不再有克隆可批），故该提案作废。

### 方案 B：把递归 `PipelineRunner` 展平为预编译循环（✅ 已落地 kode/http 3.4.0）

- **现状**：`PipelineRunner::handle()` 每次 `$next = new self($middlewares, $finalHandler, $index+1)`，
  每中间件一次对象分配 + 一次递归调用。
- **改法**：在管道**构建期**（boot 一次）把中间件链拍平成闭包数组，`handle()` 运行期用
  `for ($i=0; $i<$n; $i++) $response = $middlewares[$i]->process($request, $next);` 的索引化循环
  + 一个预编译的 `next` 闭包，去掉每中间件 `new self` 分配。
- **预期**：减少每请求中间件游标分配（次要，但零风险）。
- **✅ 已落地（kode/http 3.4.0）**：`MiddlewarePipeline::handle()` 现首次调用即 `compile()` 把中间件栈
  预编译为闭包链并复用，之后每请求直接走预编译链，去除每中间件 `new self` 分配。框架侧**零改动**，
  仅需 `composer update kode/http` 即自动受益（实测见第四节）。同一版本还把 `Response::send()` 改为
  返回 `$this` 的**空操作（向后兼容）**——即「无需再调用 `->send()`」，本框架早已采用
  `return Response::json(...)` 现代写法，故无遗留 `->send()` 调用需清理。

### 方案 C：静态路由 handler 直缓存

- `Router` 已有 `method path` 结果缓存（O(1) 命中）。可进一步把「静态路由 → 最终 handler 闭包」
  直接缓存，避免每请求 `match` 分发。属于锦上添花，优先级最低。

### 方案 D（已验证，kode/http 分发内核瘦身第一步）：`Response::json` 字符串体零资源分配 —— ✅ 实测 +16% 内核

- **现状**：`Response::json()` → `body()` → `Stream::create($body)` 每次都 `fopen('php://temp', 'r+')` +
  `fwrite` + `fseek`——即对**每个响应**创建一次流资源（即使 body 仅是字符串）。这是 `Response::json` 占单次
  `handle` ~20% 开销（实测 ~1.4µs）的主因；Slim/Nyholm 直接把字符串存内存、不 `fopen`，故更快。
- **改法**：新增 `StringStream`（纯内存持有字符串，实现完整 `StreamInterface`），让 `Stream::create(string $content)`
  直接返回 `StringStream`，**彻底去掉每响应 `fopen`**。文件 / 资源体仍走 `fopen` 路径（`createFromFile` /
  `createFromResource`），不受影响。
- **预期 / 实测**：内核 /ping 从 ~131k → **~152k（+16%）**，占裸 PHP 基线 59% → 73%；纯分发下限（预建响应、
  无 `Response::json`）已与 Slim 持平（~3.5µs）。全栈业务端点因被 13 中间件栈主导（框架税≈57%），响应构建占比小，
  故持平——印证瓶颈确在中间件栈而非响应构建。
- **归属**：该改动位于 **kode/http** 包（`vendor/kode/http/src/Psr7/StringStream.php` + `Stream::create` 改造），
  需合入 kode/http 后随 `composer update` 生效；框架侧无需改动。

### 落地顺序

A > B > D > C。**A（可变消息）/ B（管道预编译）已在 kode/http 3.4.0 落地**，**D（StringStream）已验证 +16% 内核**，
框架侧均经 `composer update` 自动受益；C（handler 直缓存）为锦上添花。

---

## 四、框架侧已做 / 可做的调优增强

### 本版已落地（v0.8.27）：审计日志离路径异步导出

与 v0.8.25 访问日志同范式——`AuditService::record()` 在热路径只做一次内存入队
（`AuditSink`，进程级静态队列），真实格式化 + 日志写入由响应后的 `shutdown` / 优雅停机钩子
批量执行，**绝不阻塞客户端响应**；`audit.async=false` 退化为同步，兼容审计强一致场景。
对被审计的**真实业务端点**移除同步日志写入（此前约 10µs/请求）；`/ping` 因在 `ignore_paths`
早退故中性。

### 框架侧下一步（建议，非本版）

> 方案 A（可变消息）/ 方案 B（管道预编译）均已在 kode/http 3.4.0 落地，框架侧经 `composer update`
> 自动受益，**无需在框架仓库改动**。3.4.0 后「每请求消息克隆」主瓶颈已消除，新前沿在下方 1–2。

1. **kode/http 分发内核继续瘦身（对应包）**：可变消息消除克隆后，in-process 全栈与最小内核在
   内核（Router 分发、Attribute 读取、DI 解析路径）已是 kode/http 分发内核范畴，且诚实框架税（全栈 ÷ 内核同端点）
   ≈ 57% 主要来自 13 个企业中间件栈，下一步应继续 profile 分发内核与 `Response::json` 分配（见方案 D）。
2. **热路径全局 skip_paths**（可选，生产探针优化）：让 `/ping`、`/health`、`/metrics` 跳过非必要
   中间件栈（已有 `JsonBodyMiddleware` / `TransactionMiddleware` 的 `skipPaths` 先例）——仅影响探针
   吞吐，对业务端点中性。
3. **真实 HTTP 同引擎重测（验证 3.4.0 收益）**：本仓库 `benchmarks/peers/webman/` 已备 Workerman
   同引擎 harness；kode 生产常驻走 kode/process 的 Workerman 多 worker 适配器（`App::run()` 经
   `server_adapter` 委托），须以 **4 worker** 与 webman 189k 同条件 wrk（注意 `App::listen()` 是
   单进程 dev server，不可用于压测）。3.4.0 前测得全栈 47k / 最小 109k，3.4.0 后应显著收窄，
   需以多 worker 实测确认新数字。

---

## 五、一句话总结

历史结论「kode 全栈比 webman 慢 4×，瓶颈在默认全栈企业中间件」是 **kode/http 不可变消息时代** 的判定。
**kode/http 3.4.0 已把消息改为可变（方案 A）+ 管道预编译（方案 B）**，消除每请求 `with*` 克隆主瓶颈：
in-process 同口径全栈业务端点占裸 PHP 比例 **9.0% → 13.4%（+48% 相对增益）**，审计税 ≈ 0%（离路径异步导出
生效）。**框架税修正**：进程隔离修正后，全栈相对内核同端点实测 ≈ 57%（13 个企业中间件栈的真实成本），旧「框架税≈0%」
为污染伪影。**框架侧零改动、`composer update` 即受益**；kode/http 3.4.0 后的新前沿在分发内核与 `Response::json`
分配（方案 D 的 StringStream 已实测 +16% 内核）。审计等「必要能力」在离路径异步导出后（v0.8.25 访问日志 /
v0.8.27 审计）已是真实路径上的**近似零成本**。
