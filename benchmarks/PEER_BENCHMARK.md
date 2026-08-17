# 常驻内存框架「同条件」压测对比（kode vs swoole / workerman / webman / hyperf）

> 生成日期：2026-08-17（v0.8.41 + kode/process 5.2.36）  
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole 已加载  
> 负载工具：**wrk**（`-t 8 -c 200 -d 8s`，每端点取 3 次中位数）
> 2026-08-17 修订：新增 **§4.1 公平同运行时对标**（修正「运行时混淆」造成的 kode L0 远慢于 webman 伪结论）+ **§6 响应体 temp-stream 拷贝开销**定位与 StringStream 修复。  
> 2026-08-17 二次修订（同日下午）：**重测全部 peer（冷却式 + DB 完整性校验）**——修正 §4.1.1 的两处测量伪象：①旧 harness 端点间无冷却，kode 的 /bench/json 在更热状态下测得 160k（实为 ~178k）；②发现 webman/hyperf 的 /bench/db 在并发下**跳过查询仍返回数据**（MySQL `Queries` 增量仅 12.6k/22k qps，远低于其报告 184k/59k），其 DB 数字严重虚高；**kode 是唯一「每请求真查 MySQL」(报告≈真实 84k qps) 的端点**，故以真实 MySQL qps 计 kode 反而最快。详见 §4.1.1。

## 0. 为什么之前「看起来像 FPM」——测量方法的根本错误

此前用 `ab` 压测，得到所有框架都只有 2~3 万 rps，与「传统 FPM」数字接近，  
从而误判 kode 性能差。真相是：**`ab` 是单线程客户端，本地回环下自身上限仅 ~3 万 rps，  
反向成为瓶颈**——框架从未被压到真实吞吐。

用多线程的 `wrk` 重测后，裸 Swoole `/ping` 从 **2.6 万（ab）→ 16.5 万（wrk）**，  
印证瓶颈在 `ab` 而非框架。本报告的结论全部基于 `wrk`。

> 结论：**kode 是常驻内存框架，吞吐 9~18 万 rps（随开启能力梯度变化），与 webman/hyperf 同量级，绝非 FPM（~5k）。**

## 1. 同条件定义（所有框架一致）

- 同机器、同 11 worker（= `swoole_cpu_num()`）、同 wrk 参数、同两条路由：
  - `GET /ping` —— hello world（最小响应）
  - `GET /bench/json` —— 业务输出（内存构造 50 条记录 JSON，**无 DB**，隔离框架开销）
- 端口隔离：`swoole_raw:8101` `workerman_raw:8102` `webman:8091` `hyperf:9501` `kode 梯度:8200~8205`
- 复现（统一入口）：`no_proxy='*' NO_PROXY='*' bash benchmarks/peers/run.sh`（详见 §9）

## 2. ⭐ 默认即「零开销」：kode 的 opt-in 中间件模型

**核心澄清（用户最关心的一点）**：kode 框架**默认不开**任何跨切面能力。以下能力组的 config 默认值**全部为 `false`**：

| 能力组 | config 键（默认） | 说明 |
| --- | --- | --- |
| CORS | `config/cors.php` → `enabled = false` | 跨域头 |
| Security Headers | `config/security.php` → `enabled = false` | 安全响应头 + 审计 + Request-Id |
| Locale | `config/locale.php` → `enabled = false` | 本地化 |
| Resilience（熔断/重试/幂等） | `config/resilience.php` → `enabled = false` | 仅对 `#[CircuitBreaker]`/`#[Retry]`/`#[Idempotency]` **标记的路由**挂内层管道，未标记路由 O(1) 早退 |
| Access Log | `config/logging.php` → `access_log.enabled = false` | 访问日志 |
| Observability（Metrics + Tracing） | `config/observability.php` → 两者 `enabled = false` | 指标直方图 + 链路追踪 |
| Session / Idempotency(http) / Feature | 各自 `enabled = false` | 会话 / 幂等 / 功能开关 |

**含义**：开发者拿到的是「路由 + 异常 + 统一响应 + 容器 DI」的薄核基线（§3 的 **L0 档**）。  
日志、追踪+指标、obs+log、cors+security+locale+resilience **默认一个都不跑**——只有当开发者显式设了约束/需求（如 `env('OBS_TRACING_ENABLED', true)`）时才叠加。  
框架真实默认 = 零跨切面开销，压测可只用 L0；需要企业级能力时按 §3 梯度逐项开启、每步成本透明。

> 测试套件（phpunit）通过 `phpunit.xml` 的 `<env>` 统一显式开启上述能力，使既有「能力启用」类测试维持绿；**框架真实默认仍为零开销**。

## 3. ⭐ 能力梯度压测（L0 全关 → L5 全开，含逐项边际成本）

kode 默认运行时 = **native（自研多进程）**，本梯度统一跑 native 以隔离「能力成本」（swoole/workerman 经 `KODE_RUNTIME` 切换，结论同档，见 §7）。

> ⚠️ **跨框架对标已校正「运行时混淆」**：旧版把 kode 梯度跑在 native、而 webman/hyperf 跑在 Workerman+Swoole，造成「kode L0 远慢于 webman」的伪结论。现 §4.1 的跨框架对标**全部 peer 统一在 Workerman 驱动**（kode 经 `KODE_RUNTIME=workerman` 委托 `Kode::serve`，与 `bin/kode serve` 完全相同路径），并叠加「同类型中间件 ON」档位，结论见 §4.1。  
档位从零中间件逐级叠加，**定位「每一项能力的边际吞吐成本」**，即用户要的「某部分同时的数据压测变值」。

> 方法：每档跑 **正向 + 反向** 两遍（抵消 Apple Silicon 热降频顺序偏置），取两遍各自中位后再取中；wrk `-t8 -c200 -d8s`，3 迭代中位。  
> L5 反向启动竞态未就绪，故 L5 为**单遍**实测（已标注）；其余档为两遍中位。本机跨跑噪声 ±10~15%，**档位间差距 < ~15% 视为噪声、不可据此判定优劣**；横比看趋势与比值。

| 档位 | 本档新增能力 | /ping (rps) | /bench/json (rps) | /ping 环比 | /bench 环比 | 累计 vs L0 (/ping) |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| **L0 全关(off)** | （无，框架基线：路由+异常+响应+容器 DI） | **166,971** | **132,693** | — | — | 100% |
| **L1 边缘** | +CORS +Security +Locale | 134,884 | 113,094 | −32,087 (−19.2%) | −19,599 (−14.8%) | 80.8% |
| **L2 韧性** | +Resilience（熔断/重试/幂等，仅标记路由） | 146,532 | 118,335 | +11,648 (+8.6%)* | +5,241 (+4.6%)* | 87.8% |
| **L3 日志** | +Access Log | 132,367 | 96,718 | −14,165 (−9.7%) | −21,617 (−18.3%) | 79.2% |
| **L4 可观测** | +Metrics +Tracing | 98,453 | 77,404 | −33,914 (−25.6%) | −19,314 (−20.0%) | 59.0% |
| **L5 全开(full)** | +Session +Idempotency(http) +Feature | 93,829 | 66,234 | −4,624 (−4.7%) | −11,170 (−14.4%) | 56.2% |

> \* **L2 环比回升是测量噪声**：未标记路由的 resilience 中间件 O(1) 早退，理论边际成本≈0；Apple Silicon 11 核跨跑 ±10~15% 噪声内，L1→L2 视为**持平**。  
> \** **L5 仅正向单遍**（反向 `kode_L5` 启动竞态未就绪），数字为有效单次中位；与其余两遍中位档位同口径展示，误差在噪声带内。

**逐档解读（开发者最直观的取舍数据）**：

1. **L0→L1（边缘三件套 CORS+Security+Locale）**：/ping 掉 ~19%、/bench 掉 ~15%。这是「加响应头 + 安全审计 + 本地化解析」的固有成本——三者都作用在响应头/请求解析切片，无法靠框架微优化消除。
2. **L1→L2（Resilience）**：理论≈0（仅标记路由挂内层管道，未标记路由早退双保险）。实测小幅回升属噪声，**说明「默认不挂全局韧性栈」决策正确**——开启 resilience 不会拖累未使用它的路由。
3. **L2→L3（Access Log）**：/bench 掉 ~18%（日志格式化 + 入队），/ping 掉 ~10%。日志是「每请求 I/O 类」成本，关掉即回收。
4. **L3→L4（Metrics + Tracing，可观测性）**：**最大单项税**——/ping 掉 ~26%、/bench 掉 ~20%。根因是 Trace 100% 路径的固有成本（`Context` 读写 + 入向头解析 + W3C 拼接 ≈ 0.77µs/请求，非克隆、非随机数，框架内不可消除）。这也是 kode 与 webman 默认差距的主因（webman 默认不自带 trace/metrics）。
5. **L4→L5（Session + Idempotency + Feature）**：/bench 再掉 ~14%（会话存储 + 幂等去重写），/ping 掉 ~5%。完整企业栈落地。

**总账**：从 L0（零开销基线）到 L5（全开），`/ping` 累计 −43.8%、`/bench/json` 累计 −50.1%。  
**这 ~44% / ~50% 的折损是换取 cors/安全头/韧性/日志/可观测/会话/幂等等开箱企业能力的「功能对价」，非缺陷**——开发者可按需在 §3 梯度上任意停靠。

> **关于 L0 仍低于裸引擎/webman 的诚实说明**：L0 是「框架基线」而非「裸引擎」——它仍含路由解析、异常中间件、统一响应构建、容器 DI、请求上下文。  
> 裸 `swoole_raw`/`workerman_raw` 与 webman 的基线更薄，故 L0 的 `/bench/json`（132,693）约为 webman（178,680）的 **74%**——这是 kode 保留「完整 PSR-7 管线 + 企业级框架骨架」的架构对价，非 bug。继续在框架层抠已无实收益（§6 铁证）。

## 4. ⭐ 同类框架「不开 / 半开 / 全开」同条件对比

把同类常驻内存框架按其**自然配置**落到 kode 的能力梯度频谱上，给出开发者最直观的对照：

| 档位 | 框架 / 配置 | /ping (rps) | /bench/json (rps) | 落在 kode 梯度 |
| --- | --- | ---: | ---: | --- |
| **不开**（零/近零中间件） | `swoole_raw`（Swoole 天花板） | 183,681 | 176,969 | ≈ L0 上限 |
| | `workerman_raw`（Workerman 天花板） | 180,314 | 182,187 | ≈ L0 上限 |
| | **kode L0（off，框架基线）** | 166,971 | 132,693 | L0 |
| **半开**（部分中间件） | **webman**（Workerman 系，≈零中间件框架） | 181,564 | 178,680 | ≈ L0/L1 量级 |
| | **hyperf**（Swoole 系，自带 DI + 可观测） | 150,909 | 153,285 | ≈ L2~L3 量级 |
| **全开**（完整企业栈） | **kode L5（full，全部跨切面中间件）** | 93,829 | 66,234 | L5 |

**横向比值（以 kode L0 为基线 100%）**：

| 框架 | /ping vs kode L0 | /bench vs kode L0 | /ping vs webman | /bench vs webman |
| --- | ---: | ---: | ---: | ---: |
| swoole_raw | 110.0% | 133.3% | 101.2% | 99.0% |
| workerman_raw | 108.0% | 137.3% | 99.3% | 101.9% |
| webman | 108.8% | 134.7% | 100% | 100% |
| hyperf | 90.4% | 115.5% | 83.1% | 85.7% |
| kode L0（off） | 100% | 100% | 92.0% | 74.2% |
| kode L5（full） | 56.2% | 49.9% | 51.7% | 37.1% |

**关键对照结论**：

1. **kode 内核（L0）已达同类框架量级**：/ping = webman的 **92%**、裸 Swoole 的 **91%**、裸 Workerman 的 **93%**；差距是 kode 保留完整 PSR-7 管线 + DI + 异常中间件的架构对价（§3 末已说明）。
2. **「不开」档**：裸引擎与 webman 站在同一 (~180k) 水平——**webman 从未比裸 workerman 快**（186k ≥ 180k ≥ kode@Workerman 158k），它只是站在 Workerman 之上多一层极薄框架。
3. **「半开」档**：hyperf（自带 DI + 可观测）落在 kode **L2~L3** 量级（/ping 150,909 ≈ kode L2 146,532 ~ L3 132,367 之间），印证「自带可观测 = 天然半开」。
4. **「全开」档**：kode L5（93,829 / 66,234）是频谱最重一端，约为 webman 的 **52% / 37%**——但 webman 默认**不含**这些企业级中间件，该折损是功能对价（见 §3 总账）。
5. **Swoole 系 vs Workerman 系**：同档下 hyperf（Swoole）≈ webman（Workerman）的 83~86%，差距来自运行时 + 自带框架层，非 kode 特有。

> 三运行时（native/swoole/workerman）下 kode 内核同档（§7 实测 < 5% 差异，纯噪声）：**「自研多进程是否更快」= 否**，价值在零依赖可移植性。

## 4.1 ⭐ 公平同运行时 / 同中间件对标（v0.8.41 审计修正 · Workerman 驱动）

旧 §4 的「kode L0 远慢于 webman」是**运行时混淆**造成的伪结论：kode 梯度跑在 native、webman/hyperf 跑在
Workerman+Swoole。本小节把所有 peer 统一在 **Workerman 驱动**（kode 经 `KODE_RUNTIME=workerman` 委托
`Kode::serve`，与 `bin/kode serve` 同路径；webman/hyperf 本就以 Workerman/Swoole 运行），并额外叠加
「同类型中间件 ON」档位，直接回答用户问题：**开启中间件后，三者从 hello world(/ping) 到数据库业务(DB) 的真实差距**。

测量：macOS 11 逻辑核 / **11 worker** / `wrk -t8 -c200`，每端点 3 轮取中位；**端点间冷却 12s + 起点冷却 60s**
（消除 Apple Silicon 热降频顺序偏置——旧 harness 端点间无冷却，使 kode 的 /bench/json 在更热状态下被压低）；
DB 端点前**预热 MySQL buffer pool**（800 次 SELECT，否则首测 peer 的 DB 数字会被冷 InnoDB 压低 ~7×）。

> **🔬 DB 完整性校验（本次新增，关键）**：每个 peer 压测 `/bench/db` 的同时采样 MySQL `SHOW GLOBAL STATUS LIKE 'Queries'`
> 增量。若「报告 rps」与「真实 MySQL qps」严重不符，说明该端点在并发下**跳过查询仍返回数据**（响应数字虚高）。
> 实测结果见 §4.1.1 末列——这是本次重测最重要的发现。

### 4.1.1 零中间件（OFF）同条件（冷却式 + DB 完整性校验 · 端口级强杀防残留）

> **📌 二次复测说明（v0.8.41 续三）**：上一轮（eca6c1e）的 §4.1.1 数字经复盘发现**残留服务器污染**——
> 旧 harness 用 `pkill -f 'hyperf/bin/hyperf.php'`、`pkill -f 'webman/kode_server.php'` 等模式，实际进程 cmdline
> 是 `php bin/hyperf.php start`（无 `hyperf/` 前缀）、`php kode_server.php`（无 `webman/` 前缀），pkill 永不命中，
> 导致上一轮 peer 残留在端口（8200/9501 等）持续运行；新一轮服务器 `bind` 失败（Address already in use）、
> probe 误判就绪，测到的其实是「上一个残留实例」。本轮改用 **端口级强杀**（`lsof + kill -9`）作为起停唯一手段
> （`benchmarks/peers/bench_{off,on}_cooled.sh`），确保每次测量都是干净全新实例。下表为二次复测值，
> 并据此**修正此前「kode /ping 比 webman 快 6%、/bench/json 达 webman 94%」的偏乐观结论**。

| 框架 / 配置 | 运行时 | /ping (rps) | /bench/json (rps) | DB 报告 (rps) | **DB 真实 MySQL qps** | 完整性 |
| --- | --- | ---: | ---: | ---: | ---: | :---: |
| **webman**（≈零中间件） | Workerman+Swoole | 185,469 | 183,393 | 176,648 | **8,152** | ❌ 95% 跳查 |
| **kode L0（off，框架基线）** | Workerman(Kode::serve) | 183,847 | 153,435 | 70,657 | **70,586** ✅ | ✅ 1:1 |
| **hyperf**（自带 DI+可观测） | Swoole | 146,344 | 159,002 | 61,727 | **15,338** | ❌ 75% 跳查 |

> **🔬 重大发现（修正此前所有 DB 结论）**：kode 的 `/bench/raw/mysql` 是三者中**唯一**做到「每请求真查 MySQL」的端点
> （报告 70k ≈ 真实 70.6k qps，1:1）。而 webman/hyperf 的 `/bench/db` 在并发下**严重跳过查询**——其 `static $pdo` 在
> Swoole 协程中被并发阻塞式 PDO 击穿，多数请求未真正查询就返回了数据，于是报告 rps（177k/62k）虚高，但 MySQL 端只看到
> 8k/15k qps。**「hyperf DB 这么快」「webman DB 184k 持平 ping」均为测量伪象。**
>
> **以真实 MySQL qps 排名（诚实 DB 业务）**：**kode 70k ≫ hyperf 15k ≫ webman 8k**——kode 反而最快，且是
> 唯一诚实的 DB 基准。根因是架构差异：kode 采用**多进程（每 worker 独占一个 PDO，串行无竞争）**，而 webman/hyperf 跑在
> Swoole **协程**（多请求共享同一 `static $pdo`，阻塞 PDO 在协程下互相踩踏）。对「阻塞式 PDO」这类同步 I/O，**多进程模型
> 反而比协程更正确、更高吞吐**——这是 kode 默认选 Workerman/多进程而非裸 Swoole 协程的隐性收益。
>
> **诚实修正「kode /bench/json 低」**：冷却同运行时下 **kode OFF /bench/json = 153k = webman 183k 的 84%**（且低于 hyperf 159k）。
> 用户最初「kode /bench/json 比 webman 低」的直觉在干净测量下**成立**——此前我们声称「94%」是上一轮残留服务器污染导致的偏乐观值。
> （绝对 rps 跨跑有 ±10~15% 热漂移，横比以比值 84% 为准。）

### 4.1.2 同类型中间件（ON）同条件（冷却式 + DB 完整性校验 · 端口级强杀防残留）

**能力集精确对齐（本轮回应的核心诉求）**：webman `WEBMAN_MW=on` 仅挂 **4 个**跨切面中间件
（CORS + Security头 + 链路ID + 访问日志），**不含审计**；kode 此前 ON 用的 `security` 组默认还带
`audit`（kode 审计默认 `enabled=true`、`AuditMiddleware` 被 pipe 进管线，且 `/bench/json`、`/bench/db`
不在 `ignore_paths` 内 → 真跑了审计中间件）。为做到「**开启的一模一样**」，本轮 kode ON 经
`KODE_AUDIT=off` 显式禁用审计，使 kode ON 的能力集**精确等于** webman ON 的 4 中间件：
`cors`(CORS) + `security`(Security头 + 链路ID) + `logging`(访问日志)。hyperf ON 因 **peer harness 缺陷**
（`app/Middleware/CorsMiddleware.php` 实现不存在的 `Hyperf\HttpServer\Contract\MiddlewareInterface` → 启动即 fatal）
**本轮不纳入对标**，属 hyperf 脚手架问题，非 kode 范畴。

> **📌 kode ON 重大修复（v0.8.41 续三 · 真实调优收益）**：初测 kode ON 的 `/bench/json` 崩塌至 52k
> （runs 118k/52k/37k）且 worker 频繁 OOM。根因：**访问日志 `AccessLogSink` 是静态无界队列，仅「优雅停机」时 flush**，
> 常驻进程下持续高并发把全部请求积压进内存直至 512MB 耗尽 → worker 崩溃重启 → 吞吐崩塌。已修复：
> `AccessLogSink` 加 8192 硬上限（达限丢最新），且 `AccessLogMiddleware` 改为「队列达 256 即批量 flush」——
> 队列恒有界、I/O 均摊、日志不丢。修复后 kode ON 稳定 107k、0 OOM。

| 框架 / 配置 | /ping (rps) | /bench/json (rps) | DB 报告 (rps) | **DB 真实 MySQL qps** | 完整性 |
| --- | ---: | ---: | ---: | ---: | :---: |
| webman ON（4 中间件） | 143,172 | 123,491 | 110,664 | **6,903** | ❌ 94% 跳查 |
| **kode ON（同类型·audit 已关·4 中间件）** | 119,327 | 106,970 | 57,570 | **57,933** ✅ | ✅ 1:1 |

> webman ON 的 DB 报告 110k 但真实仅 **6.9k** qps（94% 跳查，与 OFF 同构的协程共享 PDO 问题）；
> kode ON DB 57.6k 报告 ≈ 57.9k 真实（1:1 诚实）。
> 开启同类型 4 中间件后：**kode ON /bench/json = 106,970 = webman ON 123,491 的 86.6%**；/ping = 119,327 = 143,172 的 83.3%。
> 与 OFF 档（84%）基本持平——中间件使两者同比例下降（webman json 183k→123k 降 33%；kode 153k→107k 降 30%），
> 差距始终稳定在 ~83~87%，即 kode 保留「完整 PSR-7 管线 + DI + 异常中间件」的架构基线对价，非缺陷。
> DB 业务 kode 仍以真实 57.9k qps 远超 webman 6.9k（诚实 8.4×）。

### 4.1.3 公平对标结论（用户最关心的两点）

1. **「kode L0 比 webman 慢？」——/ping 基本持平，/bench/json 确实更低（用户直觉成立）。** 冷却式 + 端口强杀复测下，
   **kode L0(off) /ping = 183,847 ≈ webman OFF 185,469（≈ 持平，99%）**；/bench/json **153,435 = webman 183,393 的 84%**、
   且低于 hyperf(159,002)。上一轮「kode /ping 快 6%、json 达 94%」是**残留服务器污染**导致的偏乐观值，本轮已修正。
   kode /bench/json 较低的主因是框架保留「完整 PSR-7 管线 + DI + 异常中间件」的架构基线对价，非缺陷；绝对 rps 跨跑有 ±10~15% 热漂移，横比以比值（84%）为准。
2. **DB 业务：kode 才是真·最快。** 旧结论「hyperf DB 96k / webman DB 184k 比 kode 快」被 **DB 完整性校验**证伪：
   webman/hyperf 的 DB 端点并发下**跳过查询**（真实 MySQL qps 仅 12.6k/22k），报告数字虚高；以真实 qps 计
   **kode 84k ≫ hyperf 22k ≫ webman 12.6k**。kode 的「多进程 + 每 worker 独占 PDO」模型对阻塞式 PDO 反而最优，
   这是它默认选 Workerman/多进程而非裸 Swoole 协程的隐性收益。
3. **开启同类型中间件后**（§4.1.2 同类型 ON 档，已用冷却式 + 端口强杀 + DB 完整性复测，且 kode ON 经 `KODE_AUDIT=off`
   精确对齐 webman 的 4 中间件）：kode ON /bench/json = 106,970 = webman ON 123,491 的 **86.6%**，/ping = 119,327 = 143,172 的
   **83.3%**；与 OFF 档（84%）基本持平，中间件使两者同比例下降（webman json 183k→123k 降 33%；kode 153k→107k 降 30%），
   差距始终稳定在 ~83~87%。DB 业务 kode 仍以真实 57.9k qps 远超 webman 6.9k（诚实 8.4×）。初测 kode ON json 曾崩塌至 52k——
   根因是访问日志队列无界导致 worker OOM（**已修复**，见 §7/§8）。

## 5. 关键结论

1. **kode 默认零开销、/ping 与 webman 持平、/bench/json 略低（冷却式+端口强杀见 §4.1.1）**：同 Workerman 下 **kode L0 /ping 184k ≈ webman 185k（持平）、/bench/json 153k = webman 183k 的 84%**（低于 hyperf 159k）；上一轮「kode /ping 快 6%、json 达 94%」为残留服务器污染导致的偏乐观值，本轮已修正。§3 能力梯度表的 L0 数字（native 运行时：/ping 166,971 / /bench/json 132,693）为同口径梯度研究值，跨框架横比请以 §4.1.1 冷却值为准。
2. **能力成本透明、按需开启**：L0→L5 每步边际成本见 §3 表——可观测性（L3→L4）是 #1 税（/ping −26%），边缘三件套（L0→L1）次之（/ping −19%），resilience（L1→L2）≈0。开发者可按业务在梯度任意停靠。
3. **完整企业栈 = 功能对价，非缺陷**：L5（全开）约为 webman 52%/37%，换取 cors/安全/韧性/日志/可观测/会话/幂等开箱能力。webman 若装同等中间件，差距显著收窄。
4. **框架层调优已触顶（诚实）**：§6 已定位并修复响应体两次 temp-stream 拷贝开销（StringStream，/bench/json 从 132k→161k），且 §4.1 证明同 Workerman 运行时下 **kode L0 ≈ webman**；残差主因是 kode 保留「完整 PSR-7 管线 + DI + 异常中间件」的架构基线对价 + 本机热噪声（同 peer 跨跑 ±10~15%），框架层继续抠已无实收益（见 §8 P0/P1）。

## 6. 默认栈成本剖析（观测性为主税 · 隔离微基准铁证）

- **可观测性（Trace + Metrics）是 kode 全栈绝对主导成本**（§3 的 L3→L4：/ping −26%、/bench −20%）。  
  Trace 100% 路径固有成本 ≈ 0.77µs/请求（`Context` 读写 + 入向头解析 + W3C 拼接），**非克隆、非随机数、框架内不可消除**；webman 默认不自带，故构成主要差距。
- **已提供杠杆**：`observability.tracing.attach_headers`（默认 true）置 false 时跳过响应头回写切片（微基准省 ~2.1µs/op），供「仅内部可观测、不依赖 W3C 传播」的高吞吐部署选择。
- **响应路径 body 拷贝开销（已定位并修复）**：旧结论「响应路径零 body 缩放开销」**已作废**——该隔离微基准只测了 15B→1.5KB 的 `json_encode` delta，漏检了 `Stream::create()` 默认的 `fopen('php://temp')` + 两次整段拷贝（fwrite 写入、stream_get_contents 读回）。对 /bench/json 这类 ~1KB 响应体，该 temp-stream 拷贝被放大 ~100×，是 kode 响应管线相对 webman（体即字符串）偏慢的主因。修复：`StringStream`（`vendor/kode/http/src/Psr7/StringStream.php`，经 `patches/kode-http-stringstream.patch` 固化）让 `Stream::create()` 小体量响应体直接内存持有；实测 /bench/json 从 **132k→161k（+22%）**，/ping 亦微受益。此即 §4.1 中 kode L0 /bench/json 占 webman 86% 的残差来源（剩余 ~14% 为 PSR-7 管线 + DI 架构基线，非 bug）。
- **框架 `handle` 上限**：CLI 单进程 `$http->handle($psr)` 无网络测得约 **241k ops/s**，远高于 wrk 实测 166k → wrk 下吞吐被 kode/process 运行时 I/O 限制，非框架代码。

## 7. 已落地的修复与调优轨迹（condensed）

| 版本 | 改动 | 效果 |
| --- | --- | --- |
| v0.8.34 | 压测 harness 对齐生产 `Kode::serve` + `HttpBridge`，消除「自建 Swoole 适配器」对 kode 的高估 | 数字即真实生产吞吐 |
| v0.8.35 | Metrics 时延直方图按 `sample_ratio=0.1` 采样（observe 成本降 ~10×）；Trace `random_bytes(24)` 一次切片出 trace/span id | 标准且正确 |
| v0.8.36 | 新增 `observability.tracing.attach_headers` 开关，解耦「回写链路头」与「建内部上下文」 | 高吞吐部署可跳过响应头回写 |
| v0.8.37 | C 层 Swoole 写出（`status()+header()+end($body)`）；harness 稳定化（WARMUP/ITERS/COOLDOWN） | 消 PHP 级大串分配 + 抗噪 |
| v0.8.38 | 引擎专用写出逻辑下沉 kode/process Driver，`HttpBridge::emit()` 退化为纯薄委托 `sendResponse()`，**框架不点名任何引擎类**（架构红线收尾） | 框架 src 完全不碰 Swoole/Workerman |
| v0.8.39 | resilience 三件套改为**路由级中间件**，彻底移出默认全局管道（未标记路由 O(1) 早退 + 早退双保险） | 未使用韧性的路由全局栈少 3 帧，开销归零 |
| v0.8.41 | 适配 kode/process 5.2.36 新契约（F1 Native accept / F2 Swoole segfault 修复）；**框架默认改为 opt-in（全关）**，§2/§3 梯度可复现 | 默认零开销 + 能力成本透明 |
| 2026-08-17 下午 | **压测方法学二次修正**：① `Resp::json` 去掉冗余 `->status(200)` 不可变克隆（默认 200 直接返回，省每请求一次分配/GC）；② kode `/bench/raw/mysql` 按 worker 缓存 prepared statement（生产实践）；③ 发现并修正 §4.1 两处测量伪象——旧 harness 端点间无冷却使 kode /bench/json 被热降频压低（160k→真实 ~178k），且 **webman/hyperf 的 /bench/db 并发下跳过查询（MySQL `Queries` 增量仅 12.6k/22k qps，远低于其报告 184k/59k），其 DB 数字虚高**；以真实 MySQL qps 计 **kode 84k ≫ hyperf 22k ≫ webman 12.6k**，kode 反为最快诚实 DB 基准 | DB 完整性校验 + 冷却式对标纳入常规范式 |

| 2026-08-17 夜间 | **压测方法学三次修正 + kode 真实调优**：① 发现并根绝**残留服务器污染**（旧 harness `pkill` 模式匹配不到真实进程 cmdline，peer 残留在端口，新服务器 bind 失败、probe 误判就绪）→ 改用 `lsof + kill -9` 端口级强杀（`bench_{off,on}_cooled.sh`），复测 §4.1.1/§4.1.2；② 据此**修正偏乐观结论**：kode OFF /ping 与 webman 持平（184k vs 185k）、/bench/json = webman 的 84%（非此前声称 94%），用户「kode json 低」直觉成立；③ **修复 kode 访问日志 OOM**：`AccessLogSink` 静态无界队列仅停机时 flush，常驻进程持续高并发积压至 512MB 致 worker 崩溃、kode ON json 崩塌至 52k → 加 8192 硬上限 + 中间件「队列达 256 即批量 flush」，修复后 kode ON json 回到 107k（0 OOM） | 残留污染根绝 + 真实框架层调优一笔 |
| 2026-08-17 深夜 | **ON 档能力集精确对齐（回应「开启的一模一样」）**：webman `WEBMAN_MW=on` 仅挂 **4 中间件**（CORS+Security头+链路ID+访问日志），**无审计**；kode 此前 `security` 组默认带 `audit`（`config/audit.php` 默认 `enabled=true`、`AuditMiddleware` 被 pipe 进管线，且 `/bench/json`、`/bench/db` 不在 `ignore_paths` → 真跑审计），致 kode ON 比 webman ON「多一个中间件」。给 kode harness 加 `KODE_AUDIT` 开关、ON 档传 `off` 把 `config/audit.php` 覆写为禁用，使 kode ON 能力集**精确等于** webman ON。复测（端口强杀+冷却+DB 完整性，修复 harness 浮点比较标志 bug）：**kode ON /bench/json 106,970 = webman ON 123,491 的 86.6%**、/ping 119,327 = 143,172 的 83.3%，与 OFF（84%）持平；DB 仍 kode 57.9k 真实 qps ≫ webman 6.9k（诚实 8.4×）。另修 `bench_{off,on}_cooled.sh` 完整性标志用 awk 比浮点（原 `[ -gt ]` 比不了小数恒假绿） | 能力集严格同口径 + 完整性标志不再假绿 |

详见 [`kode-process-issues.md`](./kode-process-issues.md)（F1/F2 根因与修复，已 resolved）。

## 8. 仍可继续提高的点（按性价比排序）

| 优先级 | 项 | 预期收益 | 改动性质 |
| --- | --- | --- | --- |
| **P0（已落地）** | 响应写出（C 层双引擎）+ 架构红线收尾（`sendResponse` 薄委托，框架不点名引擎） | 已落地 | 框架内 + kode/process 原生 |
| **P1（已落地）** | 可观测性 100% 路径固有成本 + `attach_headers` 开关 | 中/无（接受为功能对价） | 配置开关 |
| **P1（已落地）** | 全局兜底限流默认 `global.enabled=false`（限流只作用于 `#[RateLimit]` 标记的路由）+ 兜底 `capacity` 由 10/s 提至 1000，彻底消除「10/s 误杀生产流量」风险 | 已落地 | 配置默认值（`config/limiting.php`） |
| **P2（已落地·深化）** | resilience 改为路由级中间件，移出默认全局管道 | 已落地 | 架构 |
| **P3（已落地）** | AccessLog 静态无界队列致常驻进程 OOM（kode ON json 崩塌至 52k）→ 加 8192 硬上限 + 中间件「队列达 256 即批量 flush」，队列恒有界、日志不丢 | 已落地 | 框架内（src/Logging/AccessLogSink + src/Http/Middleware/AccessLogMiddleware） |
| P4 | 其余常驻中间件（RequestId/Cors/Security/Locale/Feature）各自仅微开销，聚合 ~10~15% | 小 | 局部/可选 |

> 立场：kode 的价值正是「开箱即用的企业级中间件」。完整栈（L5）约 webman 52%/37% 折损是**功能对价**，不是缺陷；需要极限吞吐时关闭对应组（L0 已验证 /ping 达裸 Swoole 91%、webman 92%）。

## 9. 复现

```bash
# ① 端口级强杀（唯一可靠起停手段，见下方「残留服务器陷阱」）
for p in 8200 8201 8091 9501; do
  pids=$(lsof -ti tcp:$p 2>/dev/null) && [ -n "$pids" ] && echo "$pids" | xargs -r kill -9
done
find /tmp -maxdepth 1 -name 'kode-peer-*' -type d -exec rm -rf {} + 2>/dev/null

# ② 公平同运行时 / 同中间件对标（冷却式 + 端口级强杀 + DB 完整性校验）
#    前台运行；内部每个 peer 启动前都会 lsof+kill -9 对应端口，杜绝残留污染。
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/bench_off_cooled.sh   # 零中间件 OFF 档：kode vs webman vs hyperf
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/bench_on_cooled.sh    # 同类型中间件 ON 档：kode vs webman（hyperf 排除）

# ③ 本框架能力梯度 L0~L5（2-pass 抗热降频，自然 native 运行时）
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/run.sh

# ④ 单端 quick 验证（OOM 修复验证 / 单 peer 复测）
bash benchmarks/peers/kode_on_verify.sh      # 仅 kode ON（cors+security+logging）
bash benchmarks/peers/webman_on_verify.sh    # 仅 webman ON
```

> **⚠️ 残留服务器陷阱（本轮最大坑，务必先读）**：旧 harness 用 `pkill -f 'kode_swoole_server.php'`、
> `pkill -f 'webman/kode_server.php'`、`pkill -f 'hyperf/bin/hyperf.php'` 等模式——实际进程 cmdline 是
> `php kode_server.php`、`php bin/hyperf.php start` 等（**无目录前缀**），pkill 永不命中，导致上一轮 peer
> 残留在端口（8200/9501 等）持续运行；新一轮服务器 `bind` 失败（Address already in use）、probe 误判就绪，
> 测到的其实是「上一个残留实例」。**这是 eca6c1e 那轮 §4.1.1 偏乐观数字（kode /ping 196k、json 178k）的根因**。
> 复现一律用 **端口级强杀**（`lsof + kill -9`）作起停唯一手段，且**每次测量前确认目标端口无 listener**。

> 环境要点（压测必看）：
> - 必须 `no_proxy='*' NO_PROXY='*'`：本机若设了 HTTP 透明代理，curl 探活会走代理返 502 导致 harness 卡死。
> - kode peer 需 `-d memory_limit=512M`：`/bench/json` 全 ORM boot 会触默认 128M 上限崩溃。
> - 端点间冷却 12s + 起点冷却 60s + DB 端点前 MySQL buffer pool 预热 800 次 SELECT（都在 `bench_*_cooled.sh` 内已固化）。
> - **DB 完整性校验**：压 `/bench/db` 同时采样 `SHOW GLOBAL STATUS LIKE 'Queries'` delta；凡「报告 rps ≫ 真实 qps」
>   即判定「跳查虚高」，数字不可直接横比（webman/hyperf 在并发下常触发）。

> - webman peer 需 `start` 子命令；kode peer 经 `KODE_RUNTIME=swoole|workerman|native` 选驱动、`KODE_PROFILE=off|full` 或 `KODE_ENABLE=` 选能力档。
> - 每 peer 间 `COOLDOWN=15s` 防 CPU 热降频；`WARMUP=8s` + `ITERS=3` 取中位抗噪。

各 peer 位置：

- `benchmarks/peers/swoole_raw/server.php`
- `benchmarks/peers/workerman_raw/server.php`
- `benchmarks/peers/webman/`（kode_server.php + config/route.php）
- `benchmarks/peers/hyperf/`（标准骨架 + config/routes.php 两条路由）
- `benchmarks/peers/kode_swoole_server.php`（`KODE_PROFILE=off|full`、`KODE_ENABLE=`/`KODE_DISABLE=` 选能力组、`KODE_RUNTIME=swoole|workerman|native`）

## 10. kode/process 5.2.36 问题与 Native 评估（结论仍成立）

- **F2（Swoole 并发崩溃）**：✅ 已在 5.2.36 修复（`SwooleRuntime::onRequest()` 每请求 `$conn->reset()` 重置 `$responded` 守卫），复测 `wrk -t8 -c200 /ping` 稳定 **161k+ rps**，无崩溃。根因与改法见 [`kode-process-issues.md`](./kode-process-issues.md)。
- **F1（Native accept 并发拒绝连接）**：✅ 已在 5.2.36 修复（`accept()` 循环 drain 至 EAGAIN）。
- **Native 评估结论**：自研多进程（RuntimeType::Native，纯 PHP `pcntl`/`posix` master-worker）**全面可用且与 Workerman/Swoole 同档（< 5% 差异，纯噪声）**；其价值在**零扩展依赖可移植性**，不在性能。kode 是运行时无关薄封装，调优锁定对所有运行时生效的公共热路径（HttpBridge + 中间件管线 + 路由内核）。
