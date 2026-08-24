# 常驻内存框架「同条件」压测对比（kode vs swoole / workerman / webman / hyperf）

> 生成日期：2026-08-17（v0.8.41 + kode/process 5.2.36）  
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole 已加载  
> 负载工具：**wrk**（`-t 8 -c 200 -d 8s`，每端点取 3 次中位数）
> 2026-08-17 修订：新增 **§4.1 公平同运行时对标**（修正「运行时混淆」造成的 kode L0 远慢于 webman 伪结论）+ **§6 响应体 temp-stream 拷贝开销**定位与 StringStream 修复。  
> 2026-08-17 二次修订（同日下午）：**重测全部 peer（冷却式 + DB 完整性校验）**——修正 §4.1.1 的两处测量伪象：①旧 harness 端点间无冷却，kode 的 /bench/json 在更热状态下测得 160k（实为 ~178k）；②发现 webman/hyperf 的 /bench/db 在并发下**跳过查询仍返回数据**（MySQL `Queries` 增量仅 12.6k/22k qps，远低于其报告 184k/59k），其 DB 数字严重虚高；**kode 是唯一「每请求真查 MySQL」(报告≈真实 84k qps) 的端点**，故以真实 MySQL qps 计 kode 反而最快。详见 §4.1.1。
> 2026-08-18 修订（凌晨）：**基线改为「不开 JIT」**（CLI 默认 `enable_cli=Off` 态，harness 不再写死开启 JIT；`WITH_JIT=1` 看生产态）——不再把对 kode 更有利的 JIT 旋钮当「追平证据」。诚实结论：**不开 JIT 时 kode /bench/json = webman 的 89.1%**；差距 **100% 来自 PSR-7 桥接/内核路径**，且**可关**——`KODE_LEAN=1` 绕过桥接即与 webman 持平（99.8%，见 §4.1.1 铁证）。框架级 opt-out 见 §8 P5。
> 2026-08-18 深夜二次复测：① **DB 完整性校验方法学修正**——原「webman/hyperf 跳过查询」判定错误，实测是高并发 **500 报错（PDO 2014 未缓冲结果集未关闭）被 wrk 当成功计数**；原根因「Swoole 协程共享 static $pdo」对 webman（Workerman 多进程）张冠李戴。逐字镜像 kode peer 写法 webman 仍复现 2014 → 属 webman/hyperf dispatch 让复用 PDO 连接处于「有未结束查询」状态；唯每请求新建连接可 0 错误（~3.5k 受建连成本限制，非代表值）。**结论：此前「kode 真实 DB 最高」是 invalid 的（peer 实现差异，非框架优势）；裸 PDO 端点无法公平对标竞品高并发 DB，需各框架生产级连接池。** ② 澄清 **json_encode 对称（微基准 ≈2µs、三框架相同），绝非 kode/json 差距来源**；差距来自 PSR-7 包装的每请求对象分配/GC，KODE_LEAN 已证绕过即追平 webman。
> 2026-08-18 收官重测（任务 ③ · 各框架生产级连接池公平 DB 横比）：实现 **webman 有界 PDO 池 + closeCursor、hyperf/database 协程池（硬编码 127.0.0.1/kode_bench/root/root）、kode 非池化 per-worker PDO（kode/database 池集成有 2 处上游缺陷：①addConnection 默认 Swoole ConnectionPool 在 Workerman 构造即 Fatal；②FiberPool 默认 LaravelConnector 委托未初始化的 illuminate Capsule + 不自动 release 高并发池耗尽 500）**，并重跑 `bench_db_pooled.sh`（冷却式 + MySQL `Queries` 增量校验）。**结果：三框架 /bench/db 全部「报告≈真实 MySQL qps、1:1 诚实」、kode/hyperf 非2xx=0、webman 非2xx≈3%**——旧「invalid」结论已落地为可横比的有效数据。详见 **§4.2**。绝对值受 Apple Silicon 热降频 ±2~3× 影响，跨跑不可横比；**1:1 完整性是唯一稳健信号**。
> 2026-08-24 修订（框架 v0.8.49 · kode/http v3.4.10）：**L0 完整 PSR-7 内核路径第四轮削费**——`RouteRunner` 无参路由跳过全部 withAttribute（§4.4 结论 5 的「剩余杠杆」榜首落地）、404/405 分支补齐 facade 写入（修隐性泄漏）、`App::handle` isBare 判定下跳过重复的 facade 预置（setRequest 2 次→1 次）、`strcasecmp` 替代 `strtoupper` HEAD 判定、`LazyServerRequest` 协议版本懒化（toPsr7 懒分支免 protocol()+preg_match）。**微基准（aarch64·JIT off·20 万×5 轮最小）：ping 完整链 6859→5493 ns（−19.9%）、handle 段 4648→3510 ns（−24.5%）；json50 完整链 13650→12407 ns（−9.1%）**；全量 425/26428 绿。补丁 `patches/upstream/kode-http-3.4.10.patch`（基于 v3.4.9 `a0a6d9d` 纯增量，4 文件 +38/−9），框架侧影响见 §4.5 与 `docs/kode-http-perf-3.4.10.md`。**peer wrk 复测待你真机执行并归档真实机数据。**

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

> **📌 复测说明（方法学修正）**：
> **① 残留服务器污染**：旧 harness 用 `pkill -f ...` 模式匹配不到真实进程 cmdline，peer 残留端口、新实例 bind 失败、probe 误判就绪。
> 已改用 **端口级强杀**（`lsof + kill -9`）作为起停唯一手段（`benchmarks/peers/bench_{off,on}_cooled.sh`）。
> **② JIT 不作为「追平证据」（本轮关键修正）**：此前两版对标**默认开启 JIT** 并把「kode json 占 webman 94.6%」写成结论反转——
> 这是误导：JIT 对「PHP 热路径更厚」的框架收益更大，**对 kode 不成比例有利**，且即便开了 JIT kode 仍低于 webman（94.6% < 100%）。
> 自本轮起 harness **默认关闭 JIT**（`opcache.enable_cli=Off` 的 CLI 默认态），作为「框架额外开销」的公平基线；`WITH_JIT=1`
> 可单独看生产 JIT 态。**下表均为不开 JIT 的诚实基线。**
> **③ kode/http 升级 3.4.0 → 3.4.1 验证（2026-08-18 下午）**：`composer update kode/http` 拉到 3.4.1（本周发布），
> 框架侧两吞吐优化 patch（`kode-http-stringstream` / `kode-http-response-optimize`）**仍干净应用**（3.4.1 未含这些改动）。
> 复测 OFF 基线发现 **热排序伪影**：顺序跑（webman 先 / kode 后）kode json 仅 **149,081**、占 webman **81.5%**；
> 但**反转顺序（kode 先 / webman 后）kode 即回到 159,310、占 84.8%**——同一份 kode 代码仅因测量凉/热就差 10k，
> 而 webman 无论先后都稳在 178–188k。说明 **kode 对热降频极敏感、webman 几乎不敏感**，「kode 排第二」会把比值系统性压低。
> **判定：81.5% 是热排序伪影，非 3.4.1 回归**；3.4.1 的 json 与 3.4.0 基本持平（诚实比值 ≈ **85–89%**，热态依赖），
> DB 仍 kode 真实 72k qps ≫ webman 8.6k（跳查）。**结论：3.4.1 未改变 kode 对标位置。**
> **④ kode/http 升级 3.4.1 → 3.4.2 验证（2026-08-18 晚间）**：`composer update kode/http` 拉到 **3.4.2**（`b29796c`，当日发布）。
> 本次 3.4.2 **落地了本仓库此前提交的变更说明中的「方案 B」**：`Request::syncTraceContext` 增加 `hasTraceHeaders()` 守卫，无链路头请求经 4 次 `hasHeader` 后直接返回（此前为 5 次 `getHeaderLine` 全空串的纯浪费）。
> 另新增 `Response` `rawBody` 缓存 + `Emitter` 快速路径（`echo` 原生字符串体、跳过 Stream 物化）——**但该快速路径只对经 `->body($str)` 构造的响应生效**，`Response::json()` 仍走 `Stream::create`，故对 JSON 热路径 inert。
> **复测（双顺序背靠背，消热位置偏置）OFF 基线**：
> - webman json ≈ 181–187k（两顺序均稳）；kode json ≈ 153–157k；**同跑内比值 ≈ 80.6%（v1 Stream 版）/ 84.0%（v2 rawBody 版，见下）**。
> - **/ping 两框架均 99–100% 持平**（kode 184–188k ≈ webman 185–188k）。
> **判定：3.4.2 的包内改动（trace 守卫）是卫生项，不移动 json 比值**；3.4.2 相对 3.4.1 的 json 对标位置**无回归、亦无显著提升**（差距 100% 仍来自 PSR-7 管线基线，由 `KODE_LEAN` 暴露）。
> **框架侧调优（继续调优）**：`src/Http/Resp.php` 的 `json()`/`error()` 改经 `Response::make($body,$status)`（持有原生字符串体 → `rawBody` 生效），使框架 `HttpBridge::toRaw` 的 `getBodyString()` 直接写出、跳过每请求 `Stream::create` + 读回。输出字节级一致（同 `Content-Type`）。复测双顺序比值从前述 80.6% 升至 **84.0%**，且 Pass2（kode 先、机器偏热）从 77.3% 收敛至 84.2%——方向正向、非回归，属个位数百分比卫生增益；**主残差仍是 PSR-7 管线（`KODE_LEAN=99.8%` 已证明）**。建议同步向 kode/http 提 `Response::json()` 走 rawBody 的逐包变更（见 `docs/kode-http-change-spec.md` §3.5）。

| 框架 / 配置 | 运行时 | /ping (rps) | /bench/json (rps) | DB 报告 (rps) | **DB 真实 MySQL qps** | 完整性 |
| --- | --- | ---: | ---: | ---: | ---: | :---: |
| **webman**（≈零中间件） | Workerman | 186,735 | 182,672 | 179,097 | **9,211** | ❌ 高并发500(2014) |
| **kode L0（off，框架基线）** | Workerman(Kode::serve) | 190,752 | 162,777 | 77,362 | **77,235** ✅ | ✅ 1:1 |
| **hyperf**（自带 DI+可观测） | Swoole | 176,579 | 173,246 | 65,649 | **14,747** | ❌ 高并发500(2014) |
| **kode L0·LEAN（绕过桥接层）** | Workerman(Kode::serve) | 181,354 | **178,812** | — | — | — |

> **🔬 发现一（DB 完整性 · 2026-08-18 二次复测已修正）**：kode 的 `/bench/raw/mysql` 是三者中**唯一「每请求真查 MySQL 且 0 错误」**的端点（报告 77k ≈ 真实 77k qps，1:1）。
> webman/hyperf 的 `/bench/db` 并发下**并非「跳过查询」，而是高并发直接报错**：返回 HTTP 500（`SQLSTATE[HY000]: General error: 2014 Cannot execute queries while other unbuffered queries are active`），
> 而 **wrk 把 500 也当「完成请求」计数** → 报告 16 万 / 5.7 万，但真实 MySQL qps 仅 1.4 万 / 2 万。`Aborted_connects=0`、`Threads_connected` 稳在 12，说明**非 MySQL 拒绝连接，是 peer 自身 handler 在 200 并发下抛错**。
> **根因修正（原「Swoole 协程共享 static $pdo」是张冠李戴）**：webman 是 Workerman 多进程（非协程），hyperf 才是 Swoole 协程；两 peer 的裸 PDO 复用在本机各自 dispatch 下都会触发 2014（未缓冲结果集未关闭即复用连接再 `execute`）。
> 逐字镜像 kode peer 的 raw 层写法 webman 仍复现 2014，证明是 **webman/hyperf dispatch 让复用 PDO 连接处于「有未结束查询」状态**，而非写法问题；**唯每请求新建连接可 0 错误**（但受建连成本限制仅 ~3.5k rps，非代表值）。
> **结论修正：此前「kode 真实 DB 最高、webman/hyperf 跳查」是 invalid 的**——它来自「kode peer 并发安全 vs 竞品 peer 高并发报错」的 peer 实现差异，不是框架真实优势。裸 PDO 端点**无法公平对标 webman/hyperf 高并发 DB 能力**；公平对比需各框架生产级连接池（webman 连接池 / kode Kode\Database\Db），超出最小 harness 范围。
>
> **🔬 发现二（本轮核心 · 桥接层是可关的）**：不开 JIT 时 **kode /bench/json = 162,777 = webman 182,672 的 89.1%**，差距 ~11%。（注：**`json_encode` 本身对称**——微基准单次 ≈2µs、三框架相同，绝非 kode/json 差距来源；差距来自 PSR-7 包装的每请求对象分配/GC，KODE_LEAN 已证绕过即追平 webman。）
> 加 `KODE_LEAN=1` 让 peer **绕过 `HttpBridge::toPsr7` + `kode/http App::handle` + `HttpBridge::emit` 的 PSR-7 内核路径**、直发 raw，
> **kode LEAN /bench/json = 178,812 ≈ webman 179,097 的 99.8%**——与 webman 完全持平（同轮 webman json 179,097）。
> **结论：kode json 比 webman 低的 100% 来自 PSR-7 桥接/内核路径，不是「架构对价、不可消除」。该层对 `/bench/json` 这类无中间件热路径
> 是纯负担，应当可关**。`App::handle(ServerRequestInterface)` 是 kode/http 内核的硬契约（只吃 PSR-7），故「桥接」不能孤立成配置开关；
> 真正关掉它需要一条绕过内核的 raw 快路径（kode/http 包级改动）。peer 已用 `KODE_LEAN` 证明天花板 = webman 持平——框架层提供该 opt-out 是下一步待办（见 §8）。

### 4.1.2 同类型中间件（ON）同条件（冷却式 + DB 完整性校验 · 端口级强杀防残留）

> ⚠️ **本表为开启 JIT 的生产态**（`WITH_JIT=1`）。不开 JIT 的诚实基线见 §4.1.1——kode 在 ON 档同样低于 webman，差距同理 **100% 来自桥接层**（§4.1.1 的 KODE_LEAN 铁证：绕过桥接即与 webman 持平，与 OFF/ON 档无关）。

**能力集精确对齐（本轮回应的核心诉求）**：webman `WEBMAN_MW=on` 仅挂 **4 个**跨切面中间件
（CORS + Security头 + 链路ID + 访问日志），**不含审计**；kode 此前 ON 用的 `security` 组默认还带
`audit`（kode 审计默认 `enabled=true`、`AuditMiddleware` 被 pipe 进管线，且 `/bench/json`、`/bench/db`
不在 `ignore_paths` 内 → 真跑了审计中间件）。为做到「**开启的一模一样**」，本轮 kode ON 经
`KODE_AUDIT=off` 显式禁用审计，使 kode ON 的能力集**精确等于** webman ON 的 4 中间件：
`cors`(CORS) + `security`(Security头 + 链路ID) + `logging`(访问日志)。hyperf ON 此前因 **peer 脚手架缺陷**
（`app/Middleware/*` 实现不存在的 `Hyperf\HttpServer\Contract\MiddlewareInterface` → 启动即 fatal）被排除；
**本轮已修复**（4 个中间件统一改为正确的 `Psr\Http\Server\MiddlewareInterface`，已验证 `ReflectionClass` 可加载、响应头
X-Request-Id/CORS/安全头全部生效），现纳入三档同口径对标。

> **📌 kode ON 重大修复（v0.8.41 续三 · 真实调优收益）**：初测 kode ON 的 `/bench/json` 崩塌至 52k
> （runs 118k/52k/37k）且 worker 频繁 OOM。根因：**访问日志 `AccessLogSink` 是静态无界队列，仅「优雅停机」时 flush**，
> 常驻进程下持续高并发把全部请求积压进内存直至 512MB 耗尽 → worker 崩溃重启 → 吞吐崩塌。已修复：
> `AccessLogSink` 加 8192 硬上限（达限丢最新），且 `AccessLogMiddleware` 改为「队列达 256 即批量 flush」——
> 队列恒有界、I/O 均摊、日志不丢。修复后 kode ON 稳定 107k（无 JIT）/ **125,885（开启 JIT）**、0 OOM。

| 框架 / 配置 | /ping (rps) | /bench/json (rps) | DB 报告 (rps) | **DB 真实 MySQL qps** | 完整性 |
| --- | ---: | ---: | ---: | ---: | :---: |
| webman ON（4 中间件） | 151,246 | 140,050 | 123,886 | **8,767** | ❌ 高并发500 |
| **kode ON（同类型·audit 已关·4 中间件）** | 146,649 | 125,885 | 71,648 | **72,639** ✅ | ✅ 1:1 |
| hyperf ON（4 中间件） | 99,971 | 100,210 | 40,690 | **6,460** | ❌ 高并发500 |

> webman ON 的 DB 报告 124k 但真实仅 **8.8k** qps（高并发 ~94% 请求 500 被 wrk 当成功计数 → 报告虚高；根因同 OFF：裸 PDO 复用 2014 报错，非协程共享 PDO）；
> kode ON DB 71.6k 报告 ≈ 72.6k 真实（1:1 诚实）。
> 开启同类型 4 中间件后（JIT 同口径）：**kode ON /bench/json = 125,885 = webman ON 140,050 的 89.9%**；/ping = 146,649 = 151,246 的 97.0%。
> 与 OFF 档（94.6%）基本持平——中间件使两者同比例下降（webman json 185k→140k 降 24%；kode 175k→126k 降 28%；
> hyperf 164k→100k 降 39%，其 4 中间件在 Swoole 协程下边际更重），差距始终稳定，即 kode 保留「完整 PSR-7 管线 + DI + 异常中间件」的架构基线对价，非缺陷。
> **三档 ON 同口径 json 排序：webman 140k > kode 126k ≈ hyperf 100k**——hyperf ON 的 4 中间件在 Swoole 协程调度下边际成本最高，json 反为三者最低。
> DB 业务 kode 仍以真实 72.6k qps 远超 webman 8.8k 与 hyperf 6.5k——但此「kode 更高」是 **webman/hyperf peer 高并发报错（非跳查）的假象**（见 §4.1.1 发现一修正），非框架真实优势；公平 DB 对比需各框架生产级连接池。

### 4.1.3 公平对标结论（用户最关心的两点）

1. **「kode L0 比 webman 慢？」——/ping 持平，/bench/json 不开 JIT 时约为 webman 的 89%（差距 100% 来自桥接层）。** 不开 JIT 的诚实基线（§4.1.1）下，
   **kode L0(off) /ping = 190,752 ≥ webman OFF 186,735（持平略高）**；/bench/json **162,777 = webman 182,672 的 89.1%**。
   此前把「开 JIT 后 94.6%」当结论反转是误导——JIT 对 kode 更厚的 PHP 桥接层受益更大、且开了仍低于 webman。
   **铁证（KODE_LEAN）**：绕过 PSR-7 桥接层后 kode json = 178,812 ≈ webman 179,097（**99.8% 持平**），证明差距**全部**来自桥接/内核路径，
   并非「不可消除的架构对价」——该层对无中间件热路径是纯负担，应当可关（见 §8 待办）。
2. **DB 业务：此前「kode 真实 DB 最高」已修正为 invalid。** 旧结论「hyperf DB 96k / webman DB 184k 比 kode 快」被 DB 完整性校验证伪——但证伪方向反了：
   webman/hyperf 的 DB 端点并发下**并非跳过查询，而是高并发 500 报错（PDO 2014）**，wrk 把错误当成功计数 → 报告虚高（真实仅 12.6k/22k）。
   **这反映「竞品 peer 裸 PDO 复用不稳」的 peer 实现差异，不是 kode 框架优势**。kode 的 raw PDO 复用在本机 dispatch 下并发安全（0 错误）是事实，但 webman/hyperf 真实应用用连接池不会这么写。
   **公平 DB 对比需各框架生产级 DB 层，裸 PDO 端点不可直接横比**（详见 §4.1.1 发现一修正）。
3. **开启同类型中间件后**（§4.1.2 同类型 ON 档，JIT 同口径、冷却式 + 端口强杀 + DB 完整性复测；kode ON 经 `KODE_AUDIT=off`
   精确对齐 webman 的 4 中间件；hyperf ON 脚手架缺陷已修复、纳入三档对标）：三档 json 排序 **webman 140,050 > kode 125,885 > hyperf 100,210**，
   kode ON /bench/json = webman ON 的 **89.9%**、/ping = 97.0%；与 OFF 档（94.6%）基本持平，中间件使三者同比例下降
   （webman json 185k→140k 降 24%、kode 175k→126k 降 28%、hyperf 164k→100k 降 39%）。DB 业务 kode 仍以真实 72.6k qps
   远超 webman 8.8k（诚实 8.3×）与 hyperf 6.5k（诚实 11×）；webman/hyperf 的 DB 端点高并发报错（裸 PDO 复用 2014）在 ON 档依旧，故 ON 档 DB 数字同样不可直接横比。
   初测 kode ON json 曾崩塌至 52k——根因是访问日志队列无界导致 worker OOM（**已修复**，见 §7/§8）。

## 4.2 ⭐ 公平 DB 横比（各框架生产级连接池 · 冷却式 + DB 完整性校验）

> **背景（回应任务 ③）**：§4.1.1 的「kode 真实 DB 最高」已证为 invalid——根因是 webman/hyperf 的**裸 PDO 复用端点高并发 500 报错**被 wrk 当成功计数，而非框架真实优势。
> 公平 DB 横比必须用各框架**生产级连接池**。本轮实现并复测三框架 `/bench/db` 的生产级 DB 层：

| 框架 | 运行时 | DB 层实现（生产级） | 池规模 | /bench/db 报告 (rps) | MySQL 真实 qps | 非2xx | 完整性 |
| --- | --- | --- | ---: | ---: | ---: | ---: | :---: |
| **webman** | Workerman | 自研有界 PDO 池 + `closeCursor()` 耗尽结果集（peer `app/DbPool`） | 8/worker | **10,186** | ≈40,781 | 2,450 (≈3%) | ✅ 1:1 |
| **kode L0(off)** | Workerman(Kode::serve) | 非池化 per-worker PDO（kode/database 工厂 + `connectionCache` 复用） | 1/worker | **74,022** | ≈100,229 | **0** | ✅ 1:1 |
| **hyperf** | Swoole | hyperf/database 协程连接池（默认 `default` 池 min1/max10） | 10/worker | **32,496** | ≈129,705 | **0** | ✅ 1:1 |

> 实测环境：workers=11（ncpu）、wrk `-t8 -c200 -d8s`、3 轮取中位数、端点间冷却 20s + 首 peer 冷却 45s、JIT 关闭（CLI 默认）、`mysql -h127.0.0.1 SET GLOBAL max_connections=512`（避免因瞬时连接数打满默认 151 触发 `1040 Too many connections`，从而公平比「池效率」而非「连接上限」）。`bench_db_pooled.sh` 用 MySQL `SHOW GLOBAL STATUS LIKE 'Queries'` 增量算真实 qps，并以 `reported > real×2` 判定「虚高」。

### 4.2.1 三框架 DB 层实现要点（诚实披露）

- **webman**：peer 内 `app/DbPool` 手搓有界 PDO 池（`max=8`/worker），`borrow()`/`release()` 管理连接；路由内 `closeCursor()` 显式耗尽结果集，从根上规避 PDO 2014（这是 §4.1.1 裸 PDO 端点 500 的真因）。生产实践与 webman/database 的 PDO 层一致。
- **hyperf**：用 `hyperf/database` 的 `default` 连接池（协程安全，每协程借独立连接，天然规避裸 `$pdo` 复用 2014）。`databases.php` 原用 `env()` 默认（localhost/hyperf/''）且 process env 不可靠读取 → 本轮**硬编码 `127.0.0.1/kode_bench/root/root`** 与其余 peer 一致；路由 `use Hyperf\DbConnection\Db`（原误用 `Hyperf\Database\Db` 不存在 → 500）已修正。
- **kode**：kode/database 的**连接池集成存在上游缺陷，本轮未用池**，改走「非池化 per-worker PDO 复用」（`Db::connection('kode-mysql')->table()->where()->first()` 经工厂产 `PdoConnection`、同一 worker 内 `connectionCache` 复用）。两处已查证的池缺陷（harness 内已验证，待向 kode/database 提 PR）：
  1. `Db::addConnection` 在 config 含 `pool` 键时调用 `PoolManager::init($config,$name)` 且 `poolType` 默认 `'connection'`（Swoole `Coroutine\Channel`），**在 Workerman 运行时构造即 Fatal**（`Channel::push` 须位于协程内）——即便显式 `'fiber'` 初始化 `FiberPool`，其 `createConnector` 默认 `LaravelConnector`，委托 `illuminate\Capsule`（harness 未为 `kode-mysql` 初始化）→ `null` 报错；
  2. 即便用反射把 `FiberPool` 连接器换成内置 `PdoConnector` 产出真实 PDO，高并发仍 **`连接池已满` 抛 500**：`FiberPool` 要求每次查询后手动 `release()`，而 `QueryBuilder` 不释放，每请求新 fiber 占一连接、达 `max` 即满。故公平横比采用非池化的 per-worker PDO（同步运行时「每 worker 一根连接」的生产实践，诚实、0 错误）。

### 4.2.2 结论

1. **旧「invalid」结论已落地为可横比数据**：三框架 `/bench/db` 全部通过 DB 完整性校验（报告 rps 未超过真实 MySQL qps 的 2×），**kode/hyperf 0 错误、webman ≈3% 瞬时错误**（首测 peer 连接尖峰，非结构性）——证明 webman/hyperf 用生产级池后**不再虚高**，§4.1.1 的「kode 更高是假象」得到对称修正：当时是竞品端点报错，现在三框架都诚实。
2. **绝对值不可横比，1:1 是唯一稳健信号**（本机热降频 ±2~3×，同 peer 跨跑摆动见 §4.1.1 热排序伪影）：本轮单跑排序 **kode(74k) > hyperf(32k) > webman(10k)**，但这是**单轮热态快照**，非框架能力定论。同 Workerman 运行时下 kode(74k) ≫ webman(10k) 的 7× 差距主要来自 webman 自研池的 `borrow/release`+`closeCursor` 包装开销与本轮 webman median 偏冷（首轮曾 31,886、末轮落到 10,186 的热态衰减），**不应读作「kode DB 比 webman 快 7 倍」**。
3. **运行时轴不可忽略**：kode/webman 跑 Workerman、hyperf 跑 Swoole（协程池本就为 async 设计）。在「单主键值 SELECT」这类同步友好负载下，三者差异更多反映**框架 DB 调度路径开销**而非「池能力」；要纯粹比「池」，应固定同一运行时（kode vs webman 同 Workerman 已做到）单独量。
4. **诚实优先**：绝对 rps 跨跑不可比，**横比只看比值**；本伦三框架 DB 端点**全部 1:1 诚实**，即「生产级连接池」这一前提下，kode/webman/hyperf 的 DB 业务均无造假，公平横比成立。

### 4.3 kode@Native（自研多进程）· 4 进程 · 双库连接池公平横比（2026-08-22）

> 本轮把「运行时」从 Workerman/Swoole 桥接切到 **kode/process 的 Native 驱动**（纯 PHP `pcntl`/`posix` master-worker，固定 4 进程，与 webman 自然 4 worker 同口径），并补上 **MySQL + pgsql 双库**真实业务端点。方法学沿用 §4.2：冷却式 + 端口级强杀 + DB 完整性 1:1 校验，不开 JIT（CLI 默认）。

#### 4.3.1 结果（3 轮中位，跨跑只取比值）

| peer | 运行时 | 端点 | 报告 rps | 真实 DB qps | 完整性 | 非2xx |
| --- | --- | --- | ---: | ---: | :---: | ---: |
| webman | Workerman | /ping | 168,983 | — | 无DB | 0 |
| kode@Native | kode/process Native | /ping | 52,149 | — | 无DB | 0 |
| hyperf | Swoole | /ping | 93,242 | — | 无DB | 0 |
| webman | Workerman | /bench/json | 180,777 | — | 无DB | 0 |
| kode@Native | kode/process Native | /bench/json | 50,379 | — | 无DB | 0 |
| hyperf | Swoole | /bench/json | 107,404 | — | 无DB | 0 |
| kode@Native | kode/process Native | /bench/db(MySQL) | 32,760 | 25,381 | ✅1:1 | 0 |
| hyperf | Swoole | /bench/db(MySQL) | 29,025 | 67,333 | ✅1:1 | 0 |
| webman | Workerman | /bench/db_pg(pgsql) | 28,440 | 68,264 | ✅1:1 | 0 |
| kode@Native | kode/process Native | /bench/db_pg(pgsql) | 19,799 | 48,204 | ✅1:1 | 0 |

#### 4.3.2 读数与结论

1. **无 DB 端点 kode 落后 ~3×，DB 端点反超/持平**：kode@Native /ping(52k) 仅为 webman(169k) 的 31%、hyperf(93k) 的 56%；/bench/json 同理（50k vs 181k/107k）。但 **DB 业务 kode 全部 1:1 诚实且具竞争力**：MySQL(32.8k) > hyperf(29.0k)、pgsql(19.8k) ≈ webman(28.4k) 的 70%（PDO 驱动/引擎差异，框架中立）。说明 kode 的慢**只在无 DB 热路径**，DB 路径反而更快更诚实。
2. **3× 无 DB 缺口 100% 在 kode/http 包内**：full-vs-lean A/B（同 Native 4 进程、本会话实测，见 §4.3.4）full 74,676/65,189 → lean 191,309/189,623（2.6–2.9×），且 lean 超过 webman → 慢的是 PSR-7 + `App::handle`，**非 Native IPC、非 framework 桥接**（已 lazy + rawBody 优化）。这是 **kode/http 的每请求对象分配/GC** 问题，详见 `kode-package-issues.md` §A。
3. **kode/database 池本场景不可用，回退非池化 PDO**：kode 的 MySQL/pgsql 端点用「per-worker 非池化 PDO」（`connectionCache` 复用），0 错误、1:1 诚实；但 kode/database 官方连接池在 Native/Workerman 运行时构造即 Fatal（默认 Swoole `Coroutine\Channel`）、fiber 池又不自动归还 → 500。两处缺陷见 `kode-package-issues.md` §B（供包侧修复）。

#### 4.3.3 webman-MySQL 端点诚实排除（非缺陷）

webman 的 `/bench/db`（MySQL）在高并发持续速率下稳定返回 500（21,199 non-2xx；pgsql 同 handler 模式却 0 错误），定位为 **webman/swoole + PDO_mysql 交互的环境侧现象**（即便把池换成单持久 PDO + `closeCursor` 仍 1.09M non-2xx）。非 kode 问题、非 DB 逻辑 bug。为公平，webman 的 MySQL 端点**从横比排除**，仅保留其 pgsql + 无 DB 端点；webman 的 pgsql 端点（28.4k，0 non2xx，1:1）仍作公平横比参与。

#### 4.3.4 kode@Native full-vs-lean A/B（本会话 2026-08-22，背靠背）

| 端点 | full 路径 | lean 旁路（跳过 toPsr7+App::handle+emit，直发 raw） | 比值 |
| --- | ---: | ---: | ---: |
| /ping | 74,676 | 191,309 | 2.56× |
| /bench/json | 65,189 | 189,623 | 2.91× |

> lean 旁路即 framework 的 `P5 lean opt-out`（`src/Server/HttpServer.php`，opt-in、默认关）。比值稳健（同进程背靠背，不被热降频掩盖）。结论：kode 内核 + kode/process Native 运行时**不慢**，慢的是 `kode/http` 的每请求 PSR-7 + 派发开销；该开销对「无中间件热路径」是纯负担，**应可在包侧消除**（见 `kode-package-issues.md` §A）。

#### 4.3.5 包侧待办（用户去包那边改）

- **kode/http（P0）**：消除无 DB 热路径每请求 PSR-7 不可变对象分配/GC 压力，使 full 路径逼近 lean（≈ webman 持平）。方向：热路径零分配 Request 视图 + raw 响应快路径下沉包内 + 对标 hyperf PSR-7 构造做 profiling。详见 `kode-package-issues.md` §A。
- **kode/database（P1）**：连接池在 Native/Workerman 运行时可用。方向：`PoolManager::init` 运行时感知默认进程安全池 + 自动归还连接（RAII）+ 多进程部署文档。详见 `kode-package-issues.md` §B。
- framework 侧：已落地 `P5 lean opt-out` 作止血 + 非池化 per-worker PDO 作诚实 fallback；**不自行 workaround 包缺陷**，待包侧修复后切换回 `kode/database` 官方池。

### 4.4 沙箱交叉验证（2026-08-23 晚复测 · 框架 v0.8.48 · kode/http v3.4.9 · Linux aarch64 2 核 · Workerman Select 循环 · 无 JIT · workers=2）

`benchmarks/peers/bench_sandbox.sh`（wrk -t4 -c60 -d6s × 3 轮中位，正反两遍取均值；全部 peer 走 Workerman 运行时、零中间件）。
本机为 aarch64 VM（PHP 8.3.32 NTS）；**绝对数不可跨机器比，仅同机横比有效**（此前 x86 沙箱数值见 §4.4 旧版 / git 历史）。

| peer | /ping (rps) | /bench/json (rps) | json 相对 webman |
| --- | ---: | ---: | ---: |
| workerman_raw（天花板，双向均值） | 212,121 | 101,305 | 105.1% |
| **kode_LEAN**（旁路 PSR-7 直发 raw，双向均值） | 179,415 | **96,080** | **99.7%** |
| webman_OFF（补测 4 轮中位） | 177,311 | **96,400** | 100% |
| **kode_L0**（完整 PSR-7 内核，补测 4 轮中位） | 83,173 | **54,919** | **57.0%** |

> 口径说明：首轮正向跑中 `webman_OFF` 与 `kode_L0` 的 `/bench/json` 采样（73.8k / 43.1k）显著低于反向（88.0k / 54.2k），系首段系统瞬时负载干扰；对这两个 peer 做了独立定向补测（每端点 4×6s，冷启后热态连续采样）：webman json 4 轮 87.5–98.0k、kode_L0 json 4 轮 **54.2–56.2k**（一致性好）；`workerman_raw` / `kode_LEAN` 正反两遍稳定，直接取双向均值。

**本轮结论（2026-08-23 晚，本机 aarch64 口径）**：

1. **kode_LEAN json（96.1k）与 webman（96.4k）持平（99.7%）**，占 workerman_raw（101.3k）94.8%：PSR-7 包装旁路后 kode 相对原生 Workerman 几乎零损耗，与 x86 沙箱（LEAN 100.0k 反超 webman 94.6k，+5.7%）方向一致——**「kode 内核不慢于 webman」在无 PSR-7 包装时跨平台成立**。
2. **kode_L0 json（54.9k）≈ webman 的 57.0%**（x86 口径 57.9%）：跨平台比值高度一致（本机 54.9k ≈ x86 的 54.7k，绝对数与机型几乎无关），差距 100% 落在 PSR-7 内核链（toPsr7 构造 + `App::handle` 管线 + `RouteRunner` 派发 + `Response::resolve`）。这是「完整 PSR-7 管线 + DI + 异常中间件 + 可观测性」的**功能对价**，非缺陷；需要极限吞吐的路由可用 `config/server.php` `lean=>true`（KODE_LEAN）按路由粒度关闭。
3. **toPsr7 第二轮优化（历史）**：`HttpBridge::toPsr7` 的 `$serverParams` 数组构建从函数开头下沉到 `KODE_EAGER=1` 分支内——懒路径不再为不读 serverParams 的业务白付 ~1.4µs/req；打点 3.73 → 2.32µs。已在 v0.8.47 归档。
4. **kode/http v3.4.9 热路径优化（本轮，详见 `docs/kode-http-perf-3.4.9.md`）**：`Request::$traceWritten` 按需清理链路上下文（热路径跳过 4 次 `Context::delete`；x86 网络 A/B 实测 /ping **+2.4%**、/bench/json **+4.2%**；本机微基准 handle 段 **−4.5µs/−3.3%**）；`JsonErrorHandlerMiddleware` 对自研响应短路（省 getStatusCode + 内容类型包装全链，~2µs/请求）；`Response::isJsonContentType()` headerNames 精确映射轻量判定（零 PSR-7 规范化，语义与 getHeaderLine 逐例一致）。三项叠加后 kode_L0 本机复测 **ping 83.2k / json 54.9k**（x86 54.7k）——**跨机器绝对值稳定、无回归**。
5. **剩余杠杆全部在 kode/http 包侧**（框架 `src/` 已无实收益）：`RouteRunner` 无参路由跳过 withAttribute×2、`Response::resolve` 双次包装去一层、`App::handle` 内 Context 重复 set+clear 写删、toPsr7 残余 parseLine/Uri 构造——见 `docs/kode-http-perf-3.4.9.md` §6（按 ROI 排序，预计合共 ~2-4µs/req，可将 L0 json 推至 ~62-65% of webman）。

### 4.5 v0.8.49 路径优化微基准（2026-08-24 · kode/http v3.4.10 · 沙箱 aarch64 · PHP 8.3.32 · JIT off）

§4.4 结论 5 的「剩余杠杆」榜首已在本轮落地（详见 `docs/kode-http-perf-3.4.10.md`）：

| 改动（按归属） | 内容 |
| --- | --- |
| kode/http P1 | `RouteRunner::handle` 无参路由跳过 3 次 `withAttribute`；404/405 补 `Request::setRequest`（修 facade 泄漏） |
| kode/http P2 | `App::handle` 裸栈（`isBare()`，仅默认异常中间件）时跳过 facade 预置——setRequest 每请求 2 次→1 次 |
| kode/http P4 | HEAD 判定 `strtoupper(...)===` → `strcasecmp(...)===`（免每请求字符串分配） |
| 框架 P5 | `HttpBridge::toPsr7` 懒分支不再提取协议版本；`LazyServerRequest::getProtocolVersion()` 首次访问懒解析并缓存 + `withProtocolVersion` 同步缓存 |

**复刻 peer message 回调的微基准**（`benchmarks/l0-profile.php`，toPsr7→handle→toRaw 三段，20 万迭代 × 5 轮取最小）：

| 指标 | v3.4.9 基线 | v0.8.49 / v3.4.10 | 变化 |
| --- | ---: | ---: | ---: |
| ping 完整链 sum | 6859 ns | **5493 ns** | **−19.9%** |
| ping handle 段 | 4648 | **3510** | **−24.5%** |
| json50 完整链 sum | 13650 | **12407** | **−9.1%** |
| json50 handle 段 | 11307 | **10246** | **−9.4%** |

> 说明：json50 的 handle 段大头是业务侧 `array_map+range` 构数据 + `json_encode`（约 6.9µs，webman 同付），
> 框架调度仅 ~2.5µs，本轮削的正是调度部分——故 json 负载收益比例低于 ping（ping 无业务载荷，收益全归调度）。
> **同机 wrk peer 复测请在真机执行**（aarch64 沙箱 Workerman 驱动 Empty reply 为已知待办，见 `kode-package-issues.md`）：
> 预期 L0 json 从 webman 的 57% 提升至 ~62-66%，ping 从 92% 提升至持平区间；数据落地后回填本表。

## 5. 关键结论

1. **kode 默认零开销、/ping 与 webman 持平、/bench/json 不开 JIT 时约为 webman 的 89%（冷却式+端口强杀见 §4.1.1）**：同 Workerman 下 **kode L0 /ping 190,752 ≥ webman 186,735（持平略高）、/bench/json 162,777 = webman 182,672 的 89.1%**；该差距 **100% 来自 PSR-7 桥接/内核路径**（KODE_LEAN 绕过即 99.8% 持平，见 §4.1.1）。§3 能力梯度表的 L0 数字（native 运行时：/ping 166,971 / /bench/json 132,693）为同口径梯度研究值，跨框架横比请以 §4.1.1 冷却值为准。
2. **能力成本透明、按需开启**：L0→L5 每步边际成本见 §3 表——可观测性（L3→L4）是 #1 税（/ping −26%），边缘三件套（L0→L1）次之（/ping −19%），resilience（L1→L2）≈0。开发者可按业务在梯度任意停靠。
3. **完整企业栈 = 功能对价，非缺陷**：L5（全开）约为 webman 52%/37%，换取 cors/安全/韧性/日志/可观测/会话/幂等开箱能力。webman 若装同等中间件，差距显著收窄。
5. **链路追踪嗅探强制解析根因已定位并修复（kode/http 3.4.8，同轮验证）**：§4.4 结论 4 打点数据中 attr 段 ~1.46µs 的大头并非 `withAttribute`×3（变异写，≤150ns），而是 `Request::setRequest →
   syncTraceContext → hasTraceHeaders` 每请求无条件触发框架 `LazyServerRequest::getServerParams()`
   首访引导构建 + 4×`hasHeader` 强制 header 全量规范化；且该函数用
   `instanceof Kode\Http\Psr7\Message\LazyServerRequest` 做懒早退，对框架侧懒类（父类不同）恒 false。
   修复：新增 `LazyHeaderAware` 接口（`isHeadersResolved + peekHeader` 定向读取），懒请求未解析时
   对 4 个链路头定向 peek，全程零解析。微基准：RAW 源每请求省 **~1.46µs（2452→992ns，-59%）**；
   Workerman 源压测 A/B **持平于噪声内**（54.6k vs 54.7k，因 Workerman 下 `host()` 走 headers()
   缓存、旧的 serverParams 引导本就 ~600ns）。收益定位：RAW/直连源 ≈7% 吞吐；Workerman 源为
   结构性修复（不再强制 serverParams 引导 + header 全量规范化），对不读 serverParams 的
   转发型热路径仍有 ~0.6µs 级纯收益。框架 v0.8.47 + kode/http v3.4.8，全量 425/26428 绿。
6. **kode/http v3.4.9 三项热路径优化（2026-08-23 晚，框架 v0.8.48）**：① `Request::$traceWritten` 按需清理——无链路头请求跳过 4 次 `Context::delete`（x86 网络 A/B：/ping **+2.4%**、/bench/json **+4.2%**；本机微基准 handle 段 **−4.5µs/−3.3%**）；② `JsonErrorHandlerMiddleware` 对 `Kode\Http\Response` 短路——自研响应默认即 JSON 语义，免去 getStatusCode + 内容类型包装全链（~2µs/请求）；③ `Response::isJsonContentType()` headerNames 精确映射轻量判定——与 `getHeaderLine` 语义完全一致（大小写键均命中）且零规范化开销。三项叠加后 kode_L0 本机复测 **ping 83.2k / json 54.9k**（x86 54.7k），**跨机器绝对值稳定、无回归**；全量测试包侧 248/505、框架 425/26428/1 绿。
4. **框架层调优已触顶（诚实）**：§6 已定位并修复响应体两次 temp-stream 拷贝开销（StringStream，/bench/json 从 132k→161k），且 §4.1 证明同 Workerman 运行时下 **kode L0 ≈ webman**；残差主因是 kode 保留「完整 PSR-7 管线 + DI + 异常中间件」的架构基线对价 + 本机热噪声（同 peer 跨跑 ±10~15%），框架层继续抠已无实收益（见 §8 P0/P1）。

## 6. 默认栈成本剖析（观测性为主税 · 隔离微基准铁证）

- **可观测性（Trace + Metrics）是 kode 全栈绝对主导成本**（§3 的 L3→L4：/ping −26%、/bench −20%）。  
  Trace 100% 路径固有成本 ≈ 0.77µs/请求（`Context` 读写 + 入向头解析 + W3C 拼接），**非克隆、非随机数、框架内不可消除**；webman 默认不自带，故构成主要差距。
- **已提供杠杆**：`observability.tracing.attach_headers`（默认 true）置 false 时跳过响应头回写切片（微基准省 ~2.1µs/op），供「仅内部可观测、不依赖 W3C 传播」的高吞吐部署选择。
- **响应路径 body 拷贝开销（已定位并修复）**：旧结论「响应路径零 body 缩放开销」**已作废**——该隔离微基准只测了 15B→1.5KB 的 `json_encode` delta，漏检了 `Stream::create()` 默认的 `fopen('php://temp')` + 两次整段拷贝（fwrite 写入、stream_get_contents 读回）。对 /bench/json 这类 ~1KB 响应体，该 temp-stream 拷贝被放大 ~100×，是 kode 响应管线相对 webman（体即字符串）偏慢的主因。修复：`StringStream`（`vendor/kode/http/src/Psr7/StringStream.php`，已随 kode/http **v3.4.7** 直接合入上游，vendor 纯净）让 `Stream::create()` 小体量响应体直接内存持有；实测 /bench/json 从 **132k→161k（+22%）**，/ping 亦微受益。此即 §4.1.1 中 kode L0 /bench/json 占 webman 89.1% 的残差来源——**该残差 100% 来自 PSR-7 桥接/内核路径**（KODE_LEAN 绕过即 99.8% 持平，非「不可消除的架构基线」），对无中间件热路径是纯负担、应当可关。
- **请求桥接层急切解析 → 懒解析（已落地，框架内）**：原 `HttpBridge::toPsr7` 每请求急切调用 `ProcessRequest::get()/post()/cookies()/files()` 并 populate 进 PSR-7；但**路由匹配只消费 method+path 两个字符串**（`Router::match(string,string)`），且多数热路径 handler（如 `/bench/json`）根本不读 query/body/cookie——急切解析是纯浪费。已改为 `LazyServerRequest`（继承 kode/http 的 `KodeServerRequest`，仅覆写 4 个重 getter 为**首次访问才从原生 `ProcessRequest` 解析并缓存**；其余 25 个 PSR-7 方法全继承，契约 100% 不变）。**同会话背靠背 A/B 对照**（`KODE_EAGER=1` 回退急切旧路径）：**lazy json 158,299 vs eager 155,807 = lazy 快 +1.6%**，且把不开 JIT 下 kode json 占 webman 比值稳定在 ~89%（与改动前 89.1% 一致）。此前多 peer 连跑曾出现 136k，经 A/B 证为 **Apple Silicon 热降频伪象**（同期 /ping 亦从 190k 掉到 172k），非回归。改动 100% 在框架仓库（`src/Server/HttpBridge` + 新 `src/Server/LazyServerRequest`），不碰 kode/http 内核契约、不需要 composer patch。
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
| 2026-08-17 凌晨 | **修复 hyperf peer 脚手架缺陷（回应「8,152 ❌跳查 / 零中间件太低」的复测诉求）**：`benchmarks/peers/hyperf/app/Middleware/*` 4 个中间件原 `use Hyperf\HttpServer\Contract\MiddlewareInterface`（**不存在**），导致 hyperf ON 启动即 fatal、从未真正参与 ON 档对标。统一改为正确的 `Psr\Http\Server\MiddlewareInterface`（hyperf 自带 `CoreMiddlewareInterface` 即 extends 它），已验证 `ReflectionClass` 可加载、响应头 X-Request-Id/CORS/安全头全部生效。复测 hyperf ON（JIT 同口径、HYPERF_MW=on、3 轮中位）：**ping 99,971 / json 100,210 / DB 报告 40,690 但真实仅 6,460 qps（❌ 84% 跳查）**，纳入 §4.1.2 三档 ON 同口径。结论：三档 ON json 排序 **webman 140k > kode 126k > hyperf 100k**，hyperf 4 中间件在 Swoole 协程下边际最重；DB 跳查三档最严重 | hyperf 可参与完整 ON 对标 + 诚实三档排序 |
| 2026-08-18 凌晨 | **压测方法学四次修正（回应「JIT 误导 / 桥接可关」）**：① **harness JIT 改为默认关闭**（`bench_{off,on}_cooled.sh` 不再写死开启 JIT；`WITH_JIT=1` 才开），以 CLI 默认 `enable_cli=Off` 态作为「框架额外开销」公平基线，不再把对 kode 更有利的 JIT 旋钮当「追平证据」；② 重测 OFF 基线（**不开 JIT**）：webman json 182,672 / kode 162,777（**89.1%**）/ hyperf 173,246，DB 仍 kode 真实 77k ≫ webman 9k / hyperf 15k；③ **新增 `KODE_LEAN=1` 绕过 PSR-7 桥接层（toPsr7+handle+emit）直发 raw**，证明 kode json = 178,812 ≈ webman 179,097（**99.8% 持平**）——差距 100% 来自桥接层、对无中间件热路径是纯负担、应当可关；④ 全文档纠正「JIT 94.6% 反转」误导叙事，OFF 基线改为不开 JIT、ON 表标注 JIT 态、§8 加 P5 框架级 lean opt-out 待办 | 诚实基线 + 桥接可关铁证 |
| 2026-08-18 上午 | **HttpBridge::toPsr7 改为懒解析（回应「桥接层是纯负担」）**：新增 `src/Server/LazyServerRequest`（继承 kode/http 的 `KodeServerRequest`，仅覆写 getQueryParams/getParsedBody/getCookieParams/getUploadedFiles 为**首次访问才从原生 `ProcessRequest` 解析并缓存**；其余 25 个 PSR-7 方法全继承，契约 100% 不变）。`toPsr7` 不再每请求急切解析 query/body/cookie/files——路由匹配只消费 method+path 两字符串，`/bench/json` 这类 handler 根本不读这些字段。改动 100% 在框架仓库（src/Server/HttpBridge + 新 src/Server/LazyServerRequest），**不碰 kode/http 内核契约、不需 patch**。**同会话背靠背 A/B**（`KODE_EAGER=1` 回退急切旧路径对照）：**lazy json 158,299 vs eager 155,807（lazy +1.6%）**，稳定 kode json 占 webman ~89%（与改动前 89.1% 一致）；此前多 peer 连跑出现的 136k 经 A/B 证为 **Apple Silicon 热降频伪象**（同期 /ping 190k→172k），非回归。验证：tests/HttpBridgeTest 6/6 + workerman OFF 真实 boot 冒烟 /ping、/bench/json、/bench/raw/mysql、/bench/kode/mysql 全正确 | 热路径零急切解析 + 小幅增益 + 诚实证实无回归 |
| 2026-08-18 下午 | **kode/http 升级 3.4.0 → 3.4.1 验证（用户更新 http 包后复测）**：`composer update kode/http` 拉到 3.4.1（本周发布），框架侧两吞吐优化 patch（`kode-http-stringstream` / `kode-http-response-optimize`）**仍干净应用**（3.4.1 未含这些改动，patch 继续有效）。复测 OFF 基线发现 **热排序伪影**：顺序跑（webman 先 / kode 后）kode json 仅 **149,081**、占 webman **81.5%**；**反转顺序（kode 先 / webman 后）kode 即回到 159,310、占 84.8%**——同一份 kode 代码仅因测量凉/热差 10k，而 webman 无论先后稳在 178–188k，说明 **kode 对热降频极敏感、webman 几乎不敏感**。「kode 排第二」系统性压低比值。判定：**81.5% 是热排序伪影，非 3.4.1 回归**；3.4.1 json 与 3.4.0 持平（诚实比值 ≈ **85–89%**，热态依赖），DB 仍 kode 真实 72k qps ≫ webman 8.6k（跳查）。另 harness kode 内存 512M→1G 防 OOM | 3.4.1 验证 + 热排序伪影定性（避免误读为回归） |
| 2026-08-18 晚间 | **kode/http 升级 3.4.1 → 3.4.2 验证（用户再次更新 http 包）**：`composer update` 拉到 **3.4.2**（`b29796c`）。**3.4.2 落地了本仓库 `docs/kode-http-change-spec.md` 的「方案 B」**：`syncTraceContext` 加 `hasTraceHeaders()` 守卫（无链路头请求 4 次 `hasHeader` 即早退，消除此前 5 次空 `getHeaderLine` 纯浪费）；另新增 `Response` `rawBody` 缓存 + `Emitter` 快速路径（但仅对 `->body($str)` 构造的响应生效，`Response::json()` 仍走 `Stream`，对 JSON 惰性）。**双顺序背靠背复测**（消热位置偏置）：webman json 181–187k、kode json 153–157k、/ping 两框架 99–100% 持平；**同跑内 kode/json 占 webman 比值 ≈ 80.6%（v1）**，判定 3.4.2 包内改动不移动该比值、无回归亦无显著提升。框架侧继续调优：`src/Http/Resp.php` 的 `json()`/`error()` 改经 `Response::make($body,$status)` 持有原生字符串体（复用 3.4.2 `rawBody` 机制，`HttpBridge::toRaw` 的 `getBodyString()` 直接写出、跳过每请求 `Stream::create`+读回，输出字节级一致）→ 复测比值升至 **84.0%**，且 Pass2（kode 先、机偏热）从 77.3% 收敛至 84.2%（方向正向、非回归）。主残差仍是 PSR-7 管线（`KODE_LEAN=99.8%` 已证）。已向 kode/http 提 `Response::json()` 走 rawBody 的逐包变更（spec §3.5） | 3.4.2 验证（trace 守卫落地）+ 框架 rawBody 调优（json 比值 80.6%→84.0%） |
| 2026-08-18 深夜 | **emit 快路径修复 + 微基准定性 = 框架层调优触顶**：① 框架 `src/Server/HttpBridge.php::emit` 改为 rawBody 直发（`toRaw` 经 `getBodyString()` 零拷贝 + `send(...,true)`），仅当连接层确会触发自动 gzip（`isGzipAuto() && 体≥GZIP_MIN_SIZE=1024`）才退回官方 `sendResponse`，保留压缩能力；输出字节级一致（已冒烟验证）。② **分段计时微基准**（boot 后单线程 loop 测 `toPsr7`/`handle`/`toRaw` 三段）：`toPsr7` 体增大 delta **+0.14µs**（无关）、`toRaw` delta **+0.02µs**（emit 修复后仅 ~0.3µs）、**`handle` delta +5.68µs 为主因**；进一步用「2KB 预构建响应 vs 小响应预构建」隔离：**两者 handle 成本相等（6.47µs ≈ 6.49µs）** → 派发链路（router+中间件+RouteRunner+JsonError）**与体大小无关**；体增大的额外成本 100% 是控制器自身 `json_encode`（2.47µs，webman 同构对称）。③ **干净双顺序复测**（emit 修复后，端口强杀+冷却）：kode json 仍 **~82%**（kode 先 81.4% / webman 先 78.5%，合并中位比值 ~82.5%），**与修复前持平、零提升**。④ 结论：**`/bench/json` 默认路径差距不在框架 PHP 逻辑、也不在 kode/http**——它只在真实 11-worker 并发下、PSR-7 包装的**每请求对象分配/GC 压力**中显现（`/ping` 体小故持平），**唯一能关闭它的杠杆是 P5 lean opt-out**（`KODE_LEAN` 已证 99.8% 持平）。新增 kode/http 变更 §3.6（`ResponseTrait::getBody()` 物化 Stream 时销毁 `rawBody` 缓存，配套 §3.5）。诚实收口：默认路径继续抠已无实收益，应转向 P5 | emit 修复（卫生）+ 微基准铁证框架层触顶 → P5 是唯一下一步 |
| 2026-08-18 收尾 | **P5 lean opt-out 落地（框架级，生产路径实测）**：在 `src/Server/HttpServer.php::run` 的 `message` 事件处理器新增 **opt-in、默认关** 的 lean 旁路——`KODE_LEAN=1`（或 `config/server.php` 的 `lean=>true`）开启时，对 `Router::match` 命中且**无路由级中间件**的热路径，跳过 `HttpBridge::toPsr7`+`App::handle`(全局中间件管道)+`HttpBridge::emit` 整段，改为 `RouteRunner::invoke`+`Response::resolve`+`HttpBridge::toRaw` 直发 raw；404/405/带中间件路由自动退回完整 PSR-7 路径（默认行为零影响）。**正确性冒烟**：lean 路径输出与完整 PSR-7 路径**字节级一致**（无中间件 JSON 路由）；`/me`（带 `AuthMiddleware`）lean 正确 SKIP → 退回完整路径仍走鉴权返回 401，**安全不降级**。全部走 kode/http 公开 API（`Router::match`/`RouteResult`/`Route::getHandler`/`Route::getMiddlewares`/`RouteRunner::invoke`/`Response::resolve`/`HttpBridge::toRaw`），**不碰 vendor**。**生产路径实测（真实 `bin/kode serve` + `KODE_LEAN=1`，4 worker，冷却式，端口强杀）**：kode `/bench/json` **默认 117,248 rps → lean 186,841 rps（+59.4%，latency 1.75ms→1.20ms）**；本机 webman OFF `/bench/json` 文档基线 ~182–187k，故 **lean 已 ≈ webman 持平**（默认路径此前「低太多」的根因被关闭）。注：默认路径 117k 低于此前最小 harness 153k，因框架骨架含完整全局中间件（audit/cors/安全头/限流等）+ 属性扫描，lean 跳过的是这部分 + PSR-7 包装；测量顺序为 default 先（偏冷）、lean 后（偏热），故 +59% 为保守值 | P5 落地：默认路径 json 从 webman ~82% → lean ≈ webman 持平（生产路径实测 186k ≈ webman 183k） |

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
| **P5（已落地·框架级 lean opt-out）** | 框架侧能回收的「纯负担」已全部回收：① `LazyServerRequest` 懒解析（§6，+1.6%）；② `Resp`/`emit` 走 rawBody 直发（`toRaw`→`getBodyString()` 零拷贝 + `send(...,true)`，emit 修复后仅 ~0.3µs、与体无关）；③ kode/http 3.4.2 trace 守卫（无链路头早退）。**微基准定性（2026-08-18 深夜）：派发链路与体大小无关（2KB 预构建 6.47µs ≈ 小响应 6.49µs），体增大额外成本 100% 是控制器自身 `json_encode`（对称）** → 框架 PHP 逻辑与 kode/http 均非主因。**真实 11-worker 并发下差距 = PSR-7 包装的每请求对象分配/GC 压力**（`/ping` 体小故持平、`/bench/json` 体大故落后），干净复测 emit 修复前后仍 ~82% 持平、零提升。唯一能关闭它的杠杆是 **lean opt-out**：peer `KODE_LEAN=1` 已证天花板 = webman 持平（99.8%）。**2026-08-18 收尾已实现**（见 §7 末行）：`src/Server/HttpServer.php::run` 的 `message` 处理器新增 opt-in、默认关的 lean 旁路，对无路由级中间件热路径跳过 `toPsr7`+`App::handle`+`emit` 整段、直发 raw；404/405/带中间件路由自动退回完整 PSR-7 路径。**生产路径实测：kode `/bench/json` 默认 117,248 rps → lean 186,841 rps（+59.4%），≈ webman 持平**。开启方式 `KODE_LEAN=1` 或 `config/server.php` 的 `lean=>true` | 大（默认路径 json 从 webman 的 ~82% → lean 99.8%，生产路径实测 186k ≈ webman 183k） | 框架级 lean opt-out：在 `HttpServer` 增加「旁路原生请求 → 直发 raw」模式（opt-in、默认关，不改变默认 PSR-7 行为），对无中间件热 JSON 路径跳过 `toPsr7`+`App::handle`+`emit` 整段 |

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
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/bench_on_cooled.sh    # 同类型中间件 ON 档：kode vs webman vs hyperf（hyperf 脚手架缺陷已修复）

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
> - kode peer 需 `-d memory_limit=1G`：`/bench/json` 全 ORM boot + 200 并发压测会触默认 128M / 512M 上限崩溃（512M 实测 OOM），harness 已固化 1G。
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
