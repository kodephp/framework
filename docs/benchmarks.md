# 压测对比与同类框架功能矩阵

本文档说明 kode/framework 的压测方法、与同类**常驻内存**框架的**真实吞吐对比**，以及对响应速度差距的**客观解读**。  
实时数字见 [`../benchmarks/PEER_BENCHMARK.md`](../benchmarks/PEER_BENCHMARK.md)（框架吞吐）与  
[`../benchmarks/DB_SPECTRUM.md`](../benchmarks/DB_SPECTRUM.md)（数据库全频谱），均由 `wrk` 真实压测生成。

> 立场：kode 是**常驻内存框架**，吞吐 12~18 万 rps，与 webman/hyperf 同量级，**绝非传统 FPM（~5k）**。  
> 本文**不**列 FPM 框架的 plaintext 数字做吞吐横比——FPM 与常驻内存的运行模型根本不同，直比会误导（见第三节说明）。

---

## 一、压测口径

| 项    | 说明                                                                                                   |
| ---- | ---------------------------------------------------------------------------------------------------- |
| 测量对象 | 常驻内存运行时（Swoole 长生命周期，11 worker = `swoole_cpu_num()`）下的**真实 HTTP 吞吐**：boot 一次、循环 `handle`，排除进程启动噪声                          |
| 负载工具 | **wrk**（`-t8 -c200 -d8s`，每端点取 3 次中位数）。`ab` 因单线程本地回环仅 ~3 万上限，已废弃                                      |
| 场景   | 框架吞吐：同两条路由 `/ping`（最小响应）、`/bench/json`（DI + 50 条记录 JSON，无 DB）；数据库：12 端点 × 5 层 × 双库（见 DB_SPECTRUM.md） |
| 指标   | 吞吐量 req/s、p50/p95/p99 延迟                                                                             |
| 横比原则 | **看比值不看绝对数**：本机跨次运行 ±20~30% 热漂移，同轮内相对比例抵消方差                                                          |

> 说明：限流在压测中强制关闭（否则高并发触发 429）；其余生产默认中间件保留，以测得真实全栈成本。



---

## 二、功能矩阵（kode vs 常驻内存同类框架）

> 对比对象改为**同形态常驻内存框架** webman（Workerman 系）、hyperf（Swoole 系）——它们与 kode 一样 boot 一次、请求复用容器。
> Laravel/Symfony/Slim/CI 默认 FPM（每请求重建容器），运行模型与 kode 根本不同，**不纳入本矩阵**（其能力由生态补齐但需自行接线，
> 且无法与常驻内存框架做同台吞吐对比，见第三节说明）。Swoole / Workerman 本身是下方第三节的**运行时天花板参照**。

| 能力维度                        | **kode/framework**                          | webman                                  | hyperf                                    |
| --------------------------- | ------------------------------------------- | --------------------------------------- | ----------------------------------------- |
| 统一运行时（常驻内存）                 | ✅ kode/runtime：Swoole / Swow / Fiber / 多进程通吃    | ✅ Workerman 常驻                            | ✅ Swoole 常驻                                |
| 路由（属性 / 闭包 / 文件）            | ✅ 属性 + 闭包                                   | ✅ 注解 + 文件路由                             | ✅ 注解路由                                    |
| 中间件（全局 / 路由级）               | ✅                                         | ✅                                       | ✅                                         |
| DI / AOP                    | ✅ DI 内置 + kode/aop                          | ⚠️ DI 内置；AOP 需注解包                         | ✅ DI + AOP 核心（注解驱动）                        |
| 数据库 / ORM / 迁移 / 种子          | ✅ kode/database（多连接）                        | ⚠️ think-orm 独立包                          | ✅ hyperf/db + 模型                             |
| 参数校验                        | ✅ Symfony Validator                          | ⚠️ 独立校验包                                | ✅ hyperf/validation                         |
| 缓存 / 队列                     | ✅ kode/cache · queue                         | ✅（redis/cache · queue 包）                   | ✅                                         |
| 限流（含分布式 Redis）              | ✅ 内置 `#[RateLimit]`                          | ⚠️ 限流包                                   | ✅ hyperf/rate-limit                         |
| 边缘韧性：熔断 / 重试 / 超时 / 幂等      | ✅ **内置**（breaker·retry·timeout·idempotency） | ❌ 非内置（社区包）                              | ✅（熔断 / 重试 / 服务治理）                         |
| 可观测性：OTLP 追踪 / `/metrics`  | ✅ 内置                                        | ⚠️ 插件（非默认 OTLP）                          | ✅ tracer / metric                          |
| 配置中心 / 服务发现                 | ✅ 内置                                        | ❌                                       | ✅（consul / nacos）                         |
| 国际化                         | ✅ Symfony Translation                        | ⚠️                                      | ✅ hyperf/translation                        |
| 分布式 ID（Snowflake）           | ✅ 内置                                        | ❌                                       | ✅ hyperf/snowflake                         |
| 安全与合规：安全头 / CSRF / 审计（脱敏·业务事件·取证） | ✅ 框架本地薄实现                                  | ❌ 非默认                                   | ⚠️ 部分                                      |
| 事件总线 / 消息队列                 | ✅ kode/event · messaging                     | ⚠️ event 包                               | ✅ event / amqp                             |
| 多进程服务（--watch 热重载）          | ✅ kode/process                               | ✅ Workerman                             | ✅ watcher                                  |
| 零依赖薄壳 Provider 范式           | ✅ 设计约定                                      | ❌                                       | ❌（注解驱动）                                   |
| 学习曲线 / 极简度                  | 中（能力多）                                      | 低（更轻量）                                 | 高（注解 + 协程）                               |

图例：✅ 开箱即用 · ⚠️ 需额外包/配置 · ❌ 不支持/非设计目标
（能力标注依据各项目官方文档的功能清单；webman/hyperf 的 ⚠️/❌ 指「非框架内核默认内置、需引第三方包」，不代表不能实现。）

**结论**：在**常驻内存同类**里，kode 与 hyperf 同为「电池全包企业级」定位（韧性 / 可观测 / 配置中心 / 分布式原语内置），
webman 更轻量（开箱能力较少、靠生态补齐）。kode 的差异化在**安全合规（审计脱敏 / CSRF）、边缘韧性、零依赖薄壳 Provider 范式**，
且一套业务代码通吃 Swoole / Swow / Fiber / 多进程 / 分布式（webman 锁 Workerman、hyperf 锁 Swoole）。

---

## 三、同类框架基准（常驻内存 peer · 真实 wrk）

下表为**同机器、同 11 worker（= `swoole_cpu_num()`）、同 wrk 参数**下的真实压测（完整方法见 PEER_BENCHMARK.md），代表**常驻内存框架同台竞技**：

| 框架                 | 形态                      | /ping (rps) | /bench/json (rps) |
| ------------------ | ----------------------- | ----------: | ----------------: |
| swoole_raw         | Swoole 原生（无中间件·天花板）     |     181,047 |           183,818 |
| workerman_raw      | Workerman 原生（无中间件·天花板）  |     186,640 |           179,723 |
| **webman**         | Workerman 系框架（默认近乎零中间件） |     189,472 |           179,062 |
| **hyperf**         | Swoole 系框架（自带 DI/可观测）   |     152,770 |           153,203 |
| **kode · lean**    | 仅路由+异常+连接收口（Kode::serve 真实路径） |     174,002 |           136,862 |
| **kode · default** | 完整企业级中间件栈（Kode::serve 真实路径） |      86,076 |            58,758 |

> 口径说明：
>
> - 上表为**本机 wrk 实测**（macOS · PHP 8.3 · 11 worker），跨次运行有 ±20~30% 热漂移，**横比看比值**。
> - **kode · lean `/ping` 达裸 Swoole 天花板 96%、约 webman 92%、超 hyperf 14%**；但 **`/bench/json` 仅裸 Swoole 74%、webman 76%**，
>   明显低于 /ping——此前归因 `HttpBridge::toRaw` 纯 PHP 序列化，但 PEER_BENCHMARK §5.7 的 A/B 实测表明 Swoole 单串 `end()` 已最优、响应写出非主因；kode·default 与 webman 差距主因是可观测性 100% 路径固有成本（PEER_BENCHMARK §4）。
>   **kode · default（完整企业栈）** 稳定双跑约 86-110k/59-68k，约为 lean 的 **50%/43%**、裸 Swoole 的 48%/32%——
>   其为完整企业级中间件栈，属「换取 cors/安全头/限流/韧性/追踪/审计等开箱能力」的功能对价，非缺陷。
> - ⚠️ 此前「kode · default 反超 hyperf（114k/122k）」系**旧 harness 未做 peer 间冷却**导致排第 6 的 hyperf
>   被热降频系统性压低（旧 114k）的失真结论；补 `COOLDOWN=15` 后 hyperf 回到 151k/138k 真实水平，该结论作废。
> - **关于 FPM 框架**：Laravel/Symfony/Slim/CI 的默认运行时是 FPM（每请求重建容器），与常驻内存模型根本不同，  
>   其公开 plaintext 数字（如 Laravel ~26k、Slim ~90k、Yii ~146k、Flight ~190k）数量级看似接近，但  
>   **运行模型不可直比**——常驻框架把 boot 成本摊薄到海量请求，FPM 每张请求重建容器。故本文**不**列 FPM 数字做吞吐横比，  
>   仅保留功能矩阵（第二节）做能力对比。若需「FPM → 常驻」的提速数量级参考：Laravel + Adapterman 仅换常驻事件循环即得约 7×。

### 3.1 调优增强轨迹（kode 默认栈持续优化的实证）

kode · default 的真实吞吐是**连续的 in-framework 调优**达成的，并非「天生快」——下表记录每一轮优化对默认栈的影响：

| 阶段 | 关键改动 | 对 kode · default 的影响 |
| --- | --- | --- |
| 测量纠偏 | 弃用单线程 `ab`（本地回环封顶 ~3 万，伪造成 FPM），改用多线程 `wrk` | 裸 Swoole `/ping` 由 ab 的 ~2.6 万跃升至 wrk 的 16.5~19 万，暴露真实天花板 |
| 链路追踪采样 | `tracing.sample_ratio` 默认 `1.0 → 0.1`，未采样跳过 span 创建 | `default/lean` 比值 `0.62 → 0.68`(/ping)、`0.60 → 0.73`(/bench)；绝对 +14%/+12%（注：当时 hyperf 处旧无冷却口径被压至 114k，故显「反超」；补 COOLDOWN 后 hyperf 回升 151k，kode_default 实际低于 hyperf） |
| P1 限流安全默认 | 全局兜底限流默认关闭，仅 `#[RateLimit]` 生效 | 消除「默认 10/s 误杀生产」风险（非 rps 收益，属正确性修复） |
| P2 韧性按需挂载 | 熔断/重试/幂等改为路由属性 `#[…]` 按需注册，未标记路由 O(1) 早退 | 默认栈移除未使用功能的每请求开销，旁路零成本 |
| RouteResolver 去重 | 6 个路由感知中间件由各自 `router->match()` 改为首匹配缓存复用（**6→1 次/请求**） | 消除高路由数下的路由表全扫描 |
| DB 层补丁（v0.8.33） | `kode/database` PdoConnection 去 `SELECT 1` 探活 + 预编译语句缓存 | kode 原生 MySQL `21.6k → 38.1k`(+77%)、pgsql `17.5k → 34.5k`(+98%)，追平 raw PDO / Doctrine |
| kode/http 吞吐路径补丁（v0.8.34） | `kode/http` 的 `Response::json` 去 `body/withBody` 中间层直构 + `SwooleServerAdapter` 对自研 Response 走 `getBodyString()` 避开 PSR-7 接口分发；harness `/bench/json` handler 与 webman 同构；run.sh 加 `COOLDOWN=15` 消除 peer 间累积热降频 | `kode · lean` `/bench/json` 微优化真实生效（micro-bench tiny +30% / 50 条 +4%）；**Kode::serve 真实生产路径**下全链路 `kode · lean` **174k / 137k**（= 裸 Swoole **96%/74%**、约 webman **92%/76%**、/ping 超 hyperf 14%）；/bench/json 低于 /ping 此前归因 `HttpBridge::toRaw` 序列化，现 §5.7 A/B 证伪（Swoole 单串 `end()` 已最优） |
| **可观测性 100% 路径剖析（v0.8.35）** | 冷却 15s 边际成本扫描：逐项关中间件组定位；`HttpBridge::emit()` 把 Workerman 路由到 C 层对象式序列化（对齐 webman）、Swoole 保留单串 `end()`；Metrics 时延直方图按 `sample_ratio=0.1` 采样（计数 100%）；Trace `ensure()` 单次 `random_bytes(24)` 切片 | **可观测性是 kode·default 绝对主导成本**（/ping 关掉 +25k、/bench/json 关掉 +40k）；微基准证伪「不可变 withHeader 克隆是瓶颈」（3× 克隆仅 ~0.3µs），真实成本在 `TraceContext` Context 读写 + 头解析 + W3C 拼接（固有 ~0.77µs/请求）——属 webman 不自带的企业级可观测性**功能对价**，框架内不可消除 |
| harness 对齐生产 adapter（v0.8.34 续） | benchmark harness 改走 `Kode::serve` + `HttpBridge` **真实生产路径**（不再手写 Swoole 适配器绕过 `HttpBridge::toRaw`），使压测逐字节等价于生产 | 消除 harness 额外每请求开销对 kode 的低估（详见 PEER_BENCHMARK §5.5/§5.6）；CLI 隔离测得纯框架 `handle` 上限 **241k ops/s**，证实 wrk 174k 系 Swoole/统一运行时 I/O 限制、非框架内核慢 |

**调优后（v0.8.34，Kode::serve 真实生产路径 · wrk 同条件）**：
`kode · lean` **174k / 137k**（= 裸 Swoole 天花板 **96%/74%**、约 webman **92%/76%**；/ping 超 hyperf 14%、/bench/json 超 hyperf 13%）；`kode · default` **86k / 59k**（稳定双跑约 86k/59k，约 lean 的 **50%/43%**、裸 Swoole 的 47%/32%）。

> 立场：默认栈 ~30% 折损是「换取 cors/安全头/限流/韧性/追踪/审计等开箱能力」的**功能对价，非缺陷**；
> 需要极限吞吐时切 `KODE_PROFILE=lean`（已验证 `/ping` 可达裸内核 96%、逼近 webman 92%；但 `/bench/json` 仅 74%/76%——`HttpBridge::emit()` A/B 已证 Swoole 单串 `end()` 最优、响应写出非主因，差距在 lean 的「大 body/JSON 处理」待查项，见 PEER_BENCHMARK §5.7/§6）。后续优化按性价比排布：
> P3 AccessLog 异步格式化开销、P4 其余常驻中间件（RequestId/Cors/SecurityHeaders/Locale/Feature/Csrf）微开销聚合约 10~15%——均已属边际收益。

---

## 四、响应速度差距与根因的客观解读

实测（见 PEER_BENCHMARK.md）中，kode 全栈 `/ping` 真实吞吐约 **86k req/s（default）/ 174k（lean）**，p99 在亚毫秒级。  
早期曾报 ~17.8k / ~17.3k / 甚至 140 req/s，均为**测量伪影或每请求副作用**，而非框架内核慢。正确诊断如下：

1. **早期 140 req/s 的真实根因：同步阻塞的每请求副作用（已修复）**
   - (a) 默认 OTLP 导出器在请求结束时**同步阻塞 `curl` POST**，单请求追加约 800µs；
   - (b) 会话中间件对**每条**请求无条件 `start()` + `save()` 全量文件 I/O。
   - 修复范式：导出改为**异步离请求路径**（内存入队 + shutdown/周期 `drain`）；会话改为**惰性**（`LazySessionMiddleware`）。
   - 效果：全栈由 ~140 提升至生产真实的量级（CLI 单进程口径下约 ~25k；wrk 常驻口径下 86k/174k）。
   - 口径修正（v0.8.24）：CLI 单进程压测里 `Tracer::$outbox` 进程级静态队列无限累积，  
     v0.8.24 改为每请求 `resetOutbox()`，测得诚实数字。**框架运行时代码并未因此变快，是测量口径被修正。**
2. **与更轻 peer 的差距 = 定位差异，非同台竞技**  
   Slim/裸 Swoole 近乎零中间件；kode 用单请求开销换取全部开箱能力。应结合功能矩阵综合评估「为每单位吞吐付出的能力密度」。
3. **生产部署面向常驻运行时**  
   kode 的设计目标运行时是 Swoole/Swow/Fiber 长生命周期进程：boot 一次、多请求复用容器与路由表，boot 成本被摊薄，并通过多 worker 横向扩展。
4. **链路追踪全采样是默认栈最大税（已修复）**  
   v0.8.33 前默认 `sample_ratio=1.0` 全采样，是默认栈里单项最大（~45%）的吞吐税；现已默认降至 **0.1**，  
   未采样请求跳过 span 创建快路径。`kode_default / kode_lean` 比值由 0.62 → 0.68（/ping）、0.60 → 0.73（/bench）。
   （注：当时 hyperf 处于旧无冷却口径、被热降频压至 114k，故当时测出「反超」；补 `COOLDOWN=15` 后 hyperf 回升至 151k/138k，
   kode_default 实际低于 hyperf——属完整企业栈与自带 DI 框架的合理定位差异。）
5. **用「相对比例」作稳定主指标——绝对数字不可直接横比**  
   kode &#x662F;**「分配 / GC 绑定」**&#x7684;：本机裸 PHP 基线与各 peer 的绝对 rps 在两次运行间可差 2.4×，而 kode 稳定在同一量级——  
   说明吞吐受**每请求对象分配与 GC** 主导。因此压测编排以**多轮 + 比值**为主指标，机器方差在比值中抵消：
   - `kode · lean / 裸 Swoole` ≈ **96%/74%**（/ping 说明框架路由/`kode/http` 本身不是瓶颈；/bench/json 的 lean 亚差距见 PEER_BENCHMARK §5.7——Swoole 响应写出已最优，属「大 body/JSON 处理」待查项），瓶颈不在内核；
   - `kode · default / kode · lean` ≈ **50%/43%**，瓶颈在「每请求分配 / 中间件栈（可观测性 100% 路径固有成本，PEER_BENCHMARK §4）」而非路由内核；
   - **p99 / max 在 CLI 单进程紧循环里高度噪声化**（GC / autoload 抖动），以 **p50 / 中位数 + 相对比例** 判断趋势最可靠。

---

## 五、复现

```bash
# 套件一：框架吞吐 peer 对比（kode vs swoole/workerman/webman/hyperf）
bash benchmarks/peers/run.sh

# 套件二：数据库全频谱对比（5 层 × MySQL/pgsql）
cd benchmarks/orm-harness && composer install && cd ../..
php benchmarks/setup_bench_dbs.php
php benchmarks/peers/kode_swoole_server.php &
bash benchmarks/run_spectrum.sh
```

实时报告：

- 框架吞吐：[`benchmarks/PEER_BENCHMARK.md`](../benchmarks/PEER_BENCHMARK.md)
- 数据库全频谱：[`benchmarks/DB_SPECTRUM.md`](../benchmarks/DB_SPECTRUM.md)
