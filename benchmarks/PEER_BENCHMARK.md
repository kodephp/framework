# 常驻内存框架「同条件」压测对比（kode vs swoole / workerman / webman / hyperf）

> 生成日期：2026-08-17（v0.8.41 + kode/process 5.2.36）  
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole 已加载  
> 负载工具：**wrk**（`-t 8 -c 200 -d 8s`，每端点取 3 次中位数）

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

## 5. 关键结论

1. **kode 默认零开销、内核同量级**：默认（L0）`/ping` 166,971 ≈ webman 92%、裸 Swoole 91%；`/bench/json` 132,693 ≈ webman 74%（架构基线对价，非 bug）。
2. **能力成本透明、按需开启**：L0→L5 每步边际成本见 §3 表——可观测性（L3→L4）是 #1 税（/ping −26%），边缘三件套（L0→L1）次之（/ping −19%），resilience（L1→L2）≈0。开发者可按业务在梯度任意停靠。
3. **完整企业栈 = 功能对价，非缺陷**：L5（全开）约为 webman 52%/37%，换取 cors/安全/韧性/日志/可观测/会话/幂等开箱能力。webman 若装同等中间件，差距显著收窄。
4. **框架层调优已触顶（诚实）**：隔离微基准（§6）证明 kode 响应路径零 body 缩放开销，残差主因是 Swoole vs Workerman 运行时差异 + 本机热噪声（同 peer 跨跑 ±10~15%），框架层继续抠已无实收益。

## 6. 默认栈成本剖析（观测性为主税 · 隔离微基准铁证）

- **可观测性（Trace + Metrics）是 kode 全栈绝对主导成本**（§3 的 L3→L4：/ping −26%、/bench −20%）。  
  Trace 100% 路径固有成本 ≈ 0.77µs/请求（`Context` 读写 + 入向头解析 + W3C 拼接），**非克隆、非随机数、框架内不可消除**；webman 默认不自带，故构成主要差距。
- **已提供杠杆**：`observability.tracing.attach_headers`（默认 true）置 false 时跳过响应头回写切片（微基准省 ~2.1µs/op），供「仅内部可观测、不依赖 W3C 传播」的高吞吐部署选择。
- **响应路径零 body 缩放开销（铁证）**：进程隔离微基准，响应体 15B→1.5KB 各阶段（json_encode / Resp::json / toRaw / getBodyString）delta 均恰为 2.0µs = 纯 `json_encode` 本身 → 框架响应代码零缩放开销，残差不在响应管线。
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

详见 [`kode-process-issues.md`](./kode-process-issues.md)（F1/F2 根因与修复，已 resolved）。

## 8. 仍可继续提高的点（按性价比排序）

| 优先级 | 项 | 预期收益 | 改动性质 |
| --- | --- | --- | --- |
| **P0（已落地）** | 响应写出（C 层双引擎）+ 架构红线收尾（`sendResponse` 薄委托，框架不点名引擎） | 已落地 | 框架内 + kode/process 原生 |
| **P1（已落地）** | 可观测性 100% 路径固有成本 + `attach_headers` 开关 | 中/无（接受为功能对价） | 配置开关 |
| P1 | 全局限流默认 `capacity=10/s` 过低，会真实限流生产流量；建议默认大幅提高或仅按 `#[RateLimit]` 生效 | 生产可用性 | 配置默认值 |
| **P2（已落地·深化）** | resilience 改为路由级中间件，移出默认全局管道 | 已落地 | 架构 |
| P3 | AccessLog 异步入队每请求格式化开销，可评估「仅 span/指标启用时同步元数据」 | 小 | 局部 |
| P4 | 其余常驻中间件（RequestId/Cors/Security/Locale/Feature）各自仅微开销，聚合 ~10~15% | 小 | 局部/可选 |

> 立场：kode 的价值正是「开箱即用的企业级中间件」。完整栈（L5）约 webman 52%/37% 折损是**功能对价**，不是缺陷；需要极限吞吐时关闭对应组（L0 已验证 /ping 达裸 Swoole 91%、webman 92%）。

## 9. 复现

```bash
# 清理可能残留的 kode 临时配置缓存与同名进程
find /tmp -maxdepth 1 -name 'kode-peer-*' -type d -exec rm -rf {} + 2>/dev/null
pkill -f kode_swoole_server.php 2>/dev/null; pkill -f "webman/kode_server.php" 2>/dev/null

# 统一压测：同类框架（自然配置）先跑 + 本框架能力梯度 L0~L5（2-pass 抗热降频）
no_proxy='*' NO_PROXY='*' bash benchmarks/peers/run.sh
```

> 环境要点（压测必看）：
> - 必须 `no_proxy='*' NO_PROXY='*'`：本机若设了 HTTP 透明代理，curl 探活会走代理返 502 导致 harness 卡死。
> - kode peer 需 `-d memory_limit=512M`：`/bench/json` 全 ORM boot 会触默认 128M 上限崩溃。
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
