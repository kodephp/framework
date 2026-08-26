# 性能基线存档（v0.8.51 真机）

> **归档说明（v0.8.51）**：`benchmarks/` 目录已按用户要求从仓库移除（生产仓库不再携带压测工具链）。
> 本文保留 v0.8.51 真机横比的核心结论与压测口径，作为**性能基线存档**；复现脚本已删除，
> 需要重新压测时按「一、压测口径」重建同等 harness 即可。vendor 侧已知问题均已在上游 kode/* 当前版本闭环
> （kode/http 3.4.11、kode/database 1.15.8、kode/process 5.2.36），跟踪与修复在各 kode/* 仓库进行；
> 框架仓库 v0.8.51 起不再内嵌 benchmarks/ 与 vendor 问题清单。

本文档说明 kode/framework 的压测方法、与同类**常驻内存**框架的**真实吞吐对比**，以及对响应速度差距的**客观解读**。

> 立场：kode 是**常驻内存框架**，吞吐与 webman/hyperf 同量级，**绝非传统 FPM（~5k）**。
> 本文**不**列 FPM 框架的 plaintext 数字做吞吐横比——FPM 与常驻内存的运行模型根本不同，直比会误导。

> **最新基线（框架 v0.8.51 · kode/http 3.4.11 · kode/process 5.2.36 · kode/database 1.15.8 · PHP 8.3.33 · 2026-08-25 真机全量复测）**：
>
> - **vs webman（多进程对标）**：裸内核（零跨切面）`/ping` 185,362 vs 190,432（**−2.7%，持平**）；
>   能力对等档（同 4 中间件）kode **157,632 vs 127,564（+23.6%）**；L5 全开 123,175 与 webman ON 同档（−3.4%）。
> - **vs hyperf（协程对标）**：kode@Swoole 裸内核 `/ping` **186,630 vs 176,392（+5.8%）**、
>   `/bench/json` **162,843 vs 147,051（+10.7%）**、MySQL **58,866 vs 28,189（+108.8%）**。
> - **DB（连接池 + 1:1 真实查询校验）**：MySQL kode 59,225 vs hyperf 33,572（**+76%**）；
>   pgsql kode 27,922 vs webman 28,358（**持平 −1.5%**，hyperf 此版本不支持 pgsql driver，如实标注）。
> - **能力梯度 L0→L5**：186,633/166,139（L0）→ 117,822/94,474（L5），累计 −36.9%/−43.1%，
>   逐档边际成本见 §3。早前「kode 仅为 webman 31%」的差距已由 kode/http v3.4.10 + 框架 P5 关闭（归因见 §3.1）。

---

## 一、压测口径

| 项    | 说明                                                                                                   |
| ---- | ---------------------------------------------------------------------------------------------------- |
| 测量对象 | 常驻内存运行时下的**真实 HTTP 吞吐**：boot 一次、循环 `handle`，排除进程启动噪声                          |
| 负载工具 | **wrk**。当前统一口径：**`-t8 -c200 -d6s`，4 worker，每端点 3 轮取中位**（冷却式：peer 启动冷却 24s、peer 间 12s、端口级强杀防残留） |
| 场景   | `/ping`（最小响应）、`/bench/json`（DI + 50 条记录 JSON，无 DB）、`/bench/db`（MySQL 真实查询）、`/bench/db_pg`（pgsql 同 SQL） |
| 指标   | 吞吐量 req/s、非 2xx 计数、DB 完整性 1:1 校验（压测前后 `SHOW GLOBAL STATUS` / `pg_stat_database` 真实执行速率对比 wrk 报告值） |
| 横比原则 | **看比值不看绝对数**：本机跨次运行 ±20~30% 热漂移，同轮内相对比例抵消方差                                                          |

> 说明：压测中 CSRF/限流/审计均关闭（v0.8.51 起框架默认即 opt-in 全关，对标 webman 裸内核）。
> **档位口径**：`KODE_PROFILE` / `KODE_ENABLE`（逗号列表逐项开合 cors,security,locale,resilience,logging,observability）/
> `KODE_AUDIT` / `KODE_RUNTIME`（native|swoole|workerman）。harness 每次启动会**强制重写** tmp 配置
> （含 csrf/limiting 恒关），防止目录复用导致配置残留串档。
>
> **压测时务必 `APP_DEBUG=false`**（.env 默认即 false）：错误详情组装（location/chain）与调试路径
> 不在请求热路径上，但保持默认生产语义可避免任何 DEBUG 相关分支的干扰，数字更真实。

**无网络环境的替代数据链路（2026-08-25 沙箱补充 · v3.4.11 复核）**：当环境禁用 TCP/wrk（CI、隔离沙箱、无 swoole）时，
可用**纯内存微基准**（桥接层全链路 `ProcessRequest::fromRaw → toPsr7 → handle → toRaw`，预热 3000 次，二轮取稳）
做回归复核。沙箱 PHP 8.3.6 apt 构建 + kode/http **3.4.11** 全端点实测（2026-08-25，`/tmp/bench_full2.php` 同构脚本）：

| 端点 | ops/s（二轮） | ns/req（二轮） | Δ vs /ping |
| --- | ---: | ---: | ---: |
| `/ping`（最小 json） | 307,170 | 3,256 | —（归档 4.0µs，**−19%**） |
| `/bench/json`（50×3 动态构造） | 86,291 | 11,589 | +8,333 |
| `/users/42`（参数路由 `{id:\d+}`） | 266,746 | 3,749 | +493 |
| `/mid`（1 层洋葱中间件） | 281,204 | 3,556 | +301 |
| `/nope`（404 miss） | 294,882 | 3,391 | +136 |

**拆解结论（热路径调优方向）**：/bench/json 与 /ping 的 **+8.3µs 几乎 100% 来自 50×3 数组构造 + `json_encode` 语言本征**
（纯构造+encode 单测 ≈7.7µs，见 §三「json 归因」同法），**框架增量 <0.6µs**（中间件 +0.30µs、参数路由 +0.49µs、404 仅 +0.14µs）。
桥接层热路径零死角；真正可调点不在框架内，而在**应用侧数据构造/序列化**（如预编译模板、流式响应、减少动态数组层数）。
路由匹配现状 cache 命中 ≈ **175 ns**（**勿改**——static-first 直查反为 +50% 负优化，见
kode/http 当前版 §C2/C3）。绝对数与真机不可横比，仅用于回归/账单拆解。

> **⚠️ 沙箱 TCP 数据面硬门槛（2026-08-25 证实，真机 wrk 的唯一替代口径是微基准）**：本次发现沙箱
> 127.0.0.1 **TCP 连接可握手、数据不投递**——`php bin/kode serve`、标准单进程 `stream_socket_server`、
> Python `socket` 服务端全部复现「connect OK / 请求发出 / 服务端读到空 / Empty reply」，而 `stream_socket_pair`
> 与 unix domain socket（跨进程）均正常。**凡依赖 TCP 的 HTTP 压测在沙箱内不可行且与框架无关**，
> 勿再花时间排查；真机 wrk 横比请按 §一 口径在宿主机执行，沙箱只出微基准相对数据。

---

## 二、功能矩阵（kode vs 常驻内存同类框架）

> 对比对象为**同形态常驻内存框架** webman（Workerman 系，kode 的多进程对标对象）、hyperf（Swoole 系，kode 的协程对标对象）——
> 它们与 kode 一样 boot 一次、请求复用容器。Laravel/Symfony/Slim/CI 默认 FPM（每请求重建容器），运行模型根本不同，
> **不纳入本矩阵**。Swoole / Workerman 本身是运行时天花板参照（kode/process 在包侧已对标，三运行时同档，
> 见 kode/process 仓库（5.2.36 三运行时同档））。

| 能力维度                        | **kode/framework**                          | webman                                  | hyperf                                    |
| --------------------------- | ------------------------------------------- | --------------------------------------- | ----------------------------------------- |
| 统一运行时（常驻内存）                 | ✅ kode/runtime：Swoole / Swow / Fiber / 多进程通吃    | ✅ Workerman 常驻                            | ✅ Swoole 常驻                                |
| 路由（属性 / 闭包 / 文件）            | ✅ 属性 + 闭包                                   | ✅ 注解 + 文件路由                             | ✅ 注解路由                                    |
| 中间件（全局 / 路由级）               | ✅                                         | ✅                                       | ✅                                         |
| DI / AOP                    | ✅ DI 内置 + kode/aop                          | ⚠️ DI 内置；AOP 需注解包                         | ✅ DI + AOP 核心（注解驱动）                        |
| 数据库 / ORM / 迁移 / 种子          | ✅ kode/database（多连接 + 连接池）                   | ⚠️ think-orm 独立包                          | ✅ hyperf/db + 模型                             |
| 参数校验                        | ✅ Symfony Validator                          | ⚠️ 独立校验包                                | ✅ hyperf/validation                         |
| 缓存 / 队列                     | ✅ kode/cache · queue                         | ✅（redis/cache · queue 包）                   | ✅                                         |
| 限流（含分布式 Redis）              | ✅ 内置 `#[RateLimit]`（默认关，opt-in）              | ⚠️ 限流包                                   | ✅ hyperf/rate-limit                         |
| 边缘韧性：熔断 / 重试 / 超时 / 幂等      | ✅ **内置**（breaker·retry·timeout·idempotency） | ❌ 非内置（社区包）                              | ✅（熔断 / 重试 / 服务治理）                         |
| 可观测性：OTLP 追踪 / `/metrics`  | ✅ 内置（默认关，opt-in）                             | ⚠️ 插件（非默认 OTLP）                          | ✅ tracer / metric                          |
| 配置中心 / 服务发现                 | ✅ 内置                                        | ❌                                       | ✅（consul / nacos）                         |
| 国际化                         | ✅ Symfony Translation                        | ⚠️                                      | ✅ hyperf/translation                        |
| 分布式 ID（Snowflake）           | ✅ 内置                                        | ❌                                       | ✅ hyperf/snowflake                         |
| 安全与合规：安全头 / CSRF / 审计（脱敏·业务事件·取证） | ✅ 框架本地薄实现（CSRF 默认关，opt-in）                    | ❌ 非默认                                   | ⚠️ 部分                                      |
| 事件总线 / 消息队列                 | ✅ kode/event · messaging                     | ⚠️ event 包                               | ✅ event / amqp                             |
| 多进程服务（--watch 热重载）          | ✅ kode/process                               | ✅ Workerman                             | ✅ watcher                                  |
| 零依赖薄壳 Provider 范式           | ✅ 设计约定                                      | ❌                                       | ❌（注解驱动）                                   |
| 学习曲线 / 极简度                  | 中（能力多）                                      | 低（更轻量）                                 | 高（注解 + 协程）                               |

图例：✅ 开箱即用 · ⚠️ 需额外包/配置 · ❌ 不支持/非设计目标
（能力标注依据各项目官方文档的功能清单；webman/hyperf 的 ⚠️/❌ 指「非框架内核默认内置、需引第三方包」，不代表不能实现。）

**结论**：在**常驻内存同类**里，kode 与 hyperf 同为「电池全包企业级」定位（韧性 / 可观测 / 配置中心 / 分布式原语内置），
webman 更轻量（开箱能力较少、靠生态补齐）。kode 的差异化在**安全合规（审计脱敏 / CSRF）、边缘韧性、零依赖薄壳 Provider 范式**，
且一套业务代码通吃 Swoole / Swow / Fiber / 多进程 / 分布式（webman 锁 Workerman、hyperf 锁 Swoole）。
**性能上（v0.8.51 基线）**：同等能力下 kode 已 ≥ webman/hyperf（见文首基线），「企业级能力多所以慢」的旧对价叙事不再成立。

---

## 三、kode 能力梯度基准（v0.8.51 · 真机 · native · 4 worker）

下表为**框架自身**能力梯度（L0 全关 → L5 全开）的真实边际成本（`wrk -t8 -c200 -d6s` ×3 中位，2026-08-25）。

| 档位 | 本档新增能力 | /ping (rps) | /bench/json (rps) | /ping 环比 | 累计 vs L0 (/ping) |
| --- | --- | ---: | ---: | ---: | ---: |
| **L0 裸内核** | （框架基线：路由+响应+容器 DI） | **186,633** | **166,139** | — | 100% |
| **L1 边缘** | +CORS +Security +Locale | 167,861 | 146,401 | −10.1% | 89.9% |
| **L2 韧性** | +Resilience | 167,715 | 146,441 | −0.1%（免费） | 89.9% |
| **L3 日志** | +Access Log | 157,594 | 133,921 | −6.0% | 84.4% |
| **L4 可观测** | +Metrics +Tracing（sample_ratio=0.1） | 132,165 | 116,510 | −16.1% | 70.8% |
| **L5 全开** | +Profile=full +Audit | 117,822 | 94,474 | −10.9% | 63.1% |

**逐档解读（开发者最直观的取舍数据）**：

1. **L0→L1（边缘三件套 CORS+Security+Locale）**：/ping −10.1%——「加响应头 + 安全处理 + 本地化解析」的固有成本，
   作用在请求/响应切片，无法靠微优化消除；不开即免费。
2. **L1→L2（Resilience）**：**边际 ≈ 0**——韧性组件按需注册，未标记路由 O(1) 早退，实测同档（167,715 vs 167,861，噪声内）。
3. **L2→L3（Access Log）**：−6.0%——日志格式化 + 入队是每请求 I/O 类成本，关掉即回收。
4. **L3→L4（可观测性）**：**最大单项税 −16.1%**——Trace 每请求固有成本（壳 ~1.1µs + attach ~0.8µs + `ensure()` 1.8µs +
   span 摊薄 ~0.93µs ≈ 4.6µs/请求，微基准分解见 §3.2），对 /ping 与 /bench/json 无偏。
5. **L4→L5（Profile=full + Audit）**：−10.9%——完整企业栈（含审计事件）落地。

**总账**：从 L0（零开销基线）到 L5（全开），`/ping` 累计 **−36.9%**、`/bench/json` 累计 **−43.1%**。
这是换取 cors/安全头/韧性/日志/可观测/审计等开箱企业能力的**功能对价，非缺陷**——开发者可按需在梯度上任意停靠
（框架默认 opt-in 全关，即 L0 零开销）。且 **L5 全开（117,822）仍在 webman ON（127,564）同档（−3.4%）**，
比 webman 的 4 中间件还多 observability+audit+profile 三档能力。

### 3.1 历史差距归因（已全部关闭 · 索引）

早期「kode 仅为 webman 31%」的差距不是框架定位问题，而是三处具体缺陷，均已修复：

| # | 问题 | 根因 | 关闭方式 |
| --- | --- | --- | --- |
| 1 | 无 DB 端点吞吐仅为 webman 31% | kode/http 旧版每请求完整 PSR-7 对象图分配 + GC（微基准证与体大小无关） | 上游 v3.4.10（RouteRunner 无参路由、Lazy 对象、StringStream、raw 快路径、trace 守卫）+ 框架侧 P5 协议懒化；v0.8.51 实测 ping 持平（−2.7%）。残余 json 端点 −12% 的根治方向（热路径零分配 Request）见 kode/http 仓库 |
| 2 | kode/database 池在 Native 运行时不可用 | `isCoroutineRuntime` 误判 → 池降级失败 | 上游 1.15.6（PoolManager 运行时感知降级）+ 1.15.7（语句缓存 −56%/去探活/断连重试）；`isCoroutineRuntime` 真实协程上下文检测（`getUid() >= 0`）于 **1.15.8** 彻底修复（1.15.7 仍 key off `class_exists`，Native 下误判为协程）；框架侧另保留自有 `ConnectionPool`（多进程同步 PDO 池） |
| 3 | Native 并发拒绝连接 / Swoole keep-alive 崩溃 | kode/process accept 单次 drain / 跨请求 stale 响应对象 | 上游 5.2.36（循环 drain + `reset()`），修复后三运行时同档（kode/process 仓库，5.2.36 循环 drain + reset()） |
| 4 | 观测档「断层」失真 | harness 配置重写丢弃 `sample_ratio=0.1` 生产默认，压测实为 100% 全采样（单段 span 9.3µs） | harness 强制重写 tmp 配置为内存默认值；此后断层收敛为真实固有成本（§3.2） |
| 5 | 常驻进程 outbox 无界增长（内存泄漏） | 仅逻辑裁剪无物理裁剪 | 头指针 ≥ 2×cap 时 O(2×cap) 物理裁剪（摊薄 O(1)、内存封顶），回归测试覆盖 |

### 3.2 obs 成本分解（微基准铁证）

`TraceMiddleware` 每请求完整成本整链实测 ≈ **4.4µs**（四段分解合计 ≈4.6µs；`sample_ratio=0.1` 生效时）：

| 段 | 说明 | 成本 |
| --- | --- | --- |
| 壳 | 中间件包装层（获取 request/套 context/调度） | ~1.1µs |
| attach | W3C 响应头回写（3×`withHeader`） | ~0.8µs（关 `attach_headers` 可省） |
| `ensure()` | `Context` 读写 + 入向头解析 + trace/span id | **1.8µs**（热路径优化后，`3×set→1×merge` 等，−24%） |
| span 摊薄 | span 对象创建 + end 入队（0.1 采样） | ~0.93µs |

- **100% 全采样时 span 段单段成本高达 9.3µs/请求**——任何压测/调优先确认 harness 未丢弃采样默认值。
- **对 /ping 与 /bench/json 无偏**（实测两端点 4436 vs 4361 ns/op），obs 边际为每请求固定成本。
- 已排除勿重走：SHA-256 DRBG（负优化）、strace -c 聚合（attach 单 worker 失真）、outbox 性能修复本身。

---

## 四、响应速度差距与根因的客观解读

v0.8.51 真机实测（见文首基线）：kode **L0（零跨切面）** `/ping` **185,362 req/s**（webman 190,432，−2.7% 持平）；
**L5（全开企业栈）** `/ping` **117,822**（webman ON 127,564，−3.4% 同档），p99 亚毫秒级。历史测量问题与修复范式（勿重走）：

1. **早期 140 req/s 的真实根因：同步阻塞的每请求副作用（已修复）**：默认 OTLP 导出器请求结束同步阻塞 `curl` POST；
   会话中间件无条件文件 I/O。修复范式：导出改**异步离请求路径**（内存入队 + shutdown/周期 `drain`）；会话改**惰性**。
2. **早前「仅为 webman 31%」的真实根因（已关闭）**：kode/http 旧版 PSR-7 热路径每请求对象分配（§3.1 #1），
   经 v3.4.10 + 框架 P5 收敛至持平；残余 json −12% 为不可变响应物化的已知残余差，根治方向已交包侧。
3. **观测档「断层」的真实根因（已修复）**：harness 配置重写丢弃采样默认值导致 100% 全采样（§3.1 #4）；
   修复后残余 = obs 固有成本 ≈4.6µs/请求（§3.2）。
4. **常驻进程内存有界（已修复）**：`Tracer::$outbox` 物理裁剪 + 每请求 `resetOutbox()`（§3.1 #5）。
5. **用「相对比例」作稳定主指标——绝对数字不可直接横比**：kode 吞吐受每请求对象分配与 GC 主导，压测编排以
   **多轮 + 比值**为主指标，机器方差在比值中抵消：
   - `kode L0 / webman` ≈ **97%（/ping）· 88%（/bench/json）**（v0.8.51 真机）；
   - `kode parity（同 4 中间件）/ webman ON` ≈ **124% / 111%**（v0.8.51 真机）；
   - `kode@Swoole / hyperf` ≈ **106% / 111%（noDB）· 209%（MySQL）**（v0.8.51 真机）；
   - `kode L5（全开）/ kode L0` ≈ **63% / 57%**（v0.8.51 真机）；
   - **p99 / max 在紧循环里高度噪声化**，以 **p50 / 中位数 + 相对比例** 判断趋势最可靠。

---

## 五、复现口径（归档）

- **DB 预备**：MySQL + pgsql 建库 `kode_bench`，`bench_users` 表灌 1000 行；MySQL 调 `SET GLOBAL max_connections=512`。
- **压测编排**：所有 peer 起停一律按端口级强杀（`lsof + kill -9`），冷却式起停（peer 启动冷却 24s、peer 间 12s），
  手动 `Ctrl-C` 会留下孤儿 worker 静默污染下一轮数字。
- **档位开关**：`KODE_PROFILE`、`KODE_ENABLE`、`KODE_AUDIT`、`KODE_RUNTIME`（native|swoole|workerman）；
  harness 启动强制重写 tmp 配置为内存默认值（csrf/limiting 恒关、sample_ratio=0.1）。
- 原 `benchmarks/` 中的可执行脚本已随 v0.8.51 移除，按上述口径重建即可复现基线数字。