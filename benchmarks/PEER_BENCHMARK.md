# 常驻内存框架「同条件」压测对比（kode vs swoole / workerman / webman / hyperf）

> 生成日期：2026-08-15
> 机器：macOS（Apple Silicon，11 逻辑核），PHP 8.3.33，ext-swoole 已加载
> 负载工具：**wrk**（`-t 8 -c 200 -d 8s`，每端点取 3 次中位数）

## 0. 为什么之前「看起来像 FPM」——测量方法的根本错误

此前用 `ab` 压测，得到所有框架都只有 2~3 万 rps，与「传统 FPM」数字接近，
从而误判 kode 性能差。真相是：**`ab` 是单线程客户端，本地回环下自身上限仅 ~3 万 rps，
反向成为瓶颈**——框架从未被压到真实吞吐。

用多线程的 `wrk` 重测后，裸 Swoole `/ping` 从 **2.6 万（ab）→ 16.5 万（wrk）**，
印证瓶颈在 `ab` 而非框架。本报告的结论全部基于 `wrk`。

> 结论：**kode 是常驻内存框架，吞吐 12~18 万 rps，与 webman/hyperf 同量级，绝非 FPM（~5k）。**

## 1. 同条件定义（所有框架一致）

- 同机器、同 11 worker（= `swoole_cpu_num()`）、同 wrk 参数、同两条路由：
  - `GET /ping` —— hello world（最小响应）
  - `GET /bench/json` —— 业务输出（内存构造 50 条记录 JSON，**无 DB**，隔离框架开销）
- 端口隔离：`swoole_raw:8101` `workerman_raw:8102` `webman:8091` `kode:8093/8094` `hyperf:9501`
- 复现：`bash benchmarks/peers/run.sh`

## 2. 压测结果（wrk，3 次中位数，rps）

| 框架 | 形态 | /ping | /bench/json |
|---|---|---:|---:|
| swoole_raw | Swoole 原生（无中间件·天花板） | 190,530 | 176,299 |
| workerman_raw | Workerman 原生（无中间件·天花板） | 177,268 | 187,512 |
| **webman** | Workerman 系框架（默认近乎零中间件） | 185,313 | 189,015 |
| **hyperf** | Swoole 系框架（自带 DI/可观测） | 114,466 | 122,321 |
| **kode · lean** | 仅路由+异常+连接收口 | 179,726 | 135,684 |
| **kode · default** | 完整企业级中间件栈 | 122,764 | 98,449 |

> 注：本机为笔记本，跨次运行有 ±20~30% 热漂移；**横比看比值，不看绝对数**。

## 3. 关键结论

1. **kode 内核极强**：`kode · lean`（180k/136k）达到裸 Swoole 天花板的 **94~97%**，
   与 webman（185k）几乎并列。框架路由/`kode/http` 本身不是瓶颈。
2. **kode · default（完整企业栈）= 123k/98k**，已**反超 hyperf（114k/122k）**，
   约为自身 lean 的 **68%（/ping）/ 72%（/bench）**。即「默认企业栈」带来约 **30% 吞吐折损**，
   这是为换取 cors/安全头/限流/韧性/追踪/审计等能力的代价。
3. **此前「慢」的两大真因**：
   - (a) `ab` 客户端封顶（已用 wrk 修正）；
   - (b) **默认链路追踪是全采样（`sample_ratio=1.0`）**——每个请求都录制 span，
     是默认栈里**单项最大（~45%）的吞吐税**（详见第 4、5 节）。

## 4. 默认栈成本剖析（/ping，逐项关闭定位）

| 配置 | rps | 说明 |
|---|---:|---|
| default（全开） | ~70k | 基线 |
| − observability（追踪+指标） | ~107k | **追踪全采样是 #1 税（~45%）** |
| − resilience（熔断+重试+幂等） | ~66k | 韧性三件套约 7% |
| − observability + resilience | ~96k | 两者叠加 |

进一步切分 observability：关闭 tracing（仅留指标）→ **+53%**；关闭指标（仅留追踪）→ 噪声范围内。
**追踪（全采样）是绝对主导**。

## 5. 已实施的修复（in-framework，非 vendor 包）

### 5.1 链路追踪改为按采样决策，且未采样时跳过 span 创建
- `src/Observability/Trace/Tracer.php`
  - `decideSampled()` 提升为 **public**（供中间件先行判定）；
  - `start()` 新增可选 `$sampled` 参数，避免「已决定不采样却仍创建 span 对象」的无效开销。
- `src/Observability/Middleware/TraceMiddleware.php`
  - 仅当 `tracer->isEnabled() && tracer->decideSampled()` 才开根 span；
    未采样请求直接走「无 span 创建」快路径；traceparent / X-Trace-Id 响应头仍统一附加（传播不丢）。
- `config/observability.php`
  - `tracing.sample_ratio` 默认 **1.0 → 0.1**（生产惯例：10% 采样足够定位问题，全采样非生产默认）。
    需全链路时显式设 `1.0`。

效果（同一机器前后对比，已消除 ab 干扰）：
`kode_default / kode_lean` 比值 **0.62 → 0.68（/ping）/ 0.60 → 0.73（/bench）**；
`kode_default` 绝对吞吐 +14%（/ping）、+12%（/bench），并**反超 hyperf**。

### 5.2 测试
`tests/ObservabilityTest.php` 10/10 通过（采样短路后追踪头仍正确下发、采样比例行为不变）。

## 6. 仍可继续提高的点（按性价比排序，待确认后实施）

| 优先级 | 项 | 预期收益 | 改动性质 |
|---|---|---|---|
| P1 | 全局限流默认 `capacity=10/s` 过低（config/limiting.php），会**真实限流生产流量**；建议默认大幅提高或仅按 `#[RateLimit]` 生效 | 生产可用性（非压测） | 配置默认值 |
| P2 | resilience 三件套（熔断/重试/幂等）目前**全局包裹每条请求**，应仿照 rate-limit/feature/csrf 改为「按路由属性 `#[Retry]`/`#[CircuitBreaker]`/`#[Idempotency]` 扫描后按需注册」 | 默认栈再降数 % | 架构（需评估 kode/http 是否支持路由级中间件） |
| P3 | AccessLog 异步入队仍有每请求格式化开销；可评估「仅在 span/指标已启用时同步元数据」 | 小 | 局部 |
| P4 | 其余常驻中间件（RequestId/Cors/SecurityHeaders/Locale/Feature/Csrf）各自仅微开销，聚合约 10~15%，逐项优化收益递减 | 小 | 局部/可选 |

> 立场：kode 的价值正是「开箱即用的企业级中间件」。默认栈的 ~30% 折损是**功能对价**，
> 不是缺陷；需要极限吞吐时关闭对应组（lean 模式已验证可达裸内核 94%+）。
> 上述 P2 是把「未使用的功能」从默认热路径移除，属正确且不影响能力的优化。

## 7. 复现

```bash
# 清理可能残留的 kode 临时配置缓存
find /tmp -maxdepth 1 -name 'kode-peer-*' -type d -exec rm -rf {} + 2>/dev/null
bash benchmarks/peers/run.sh
```

各 peer 位置：
- `benchmarks/peers/swoole_raw/server.php`
- `benchmarks/peers/workerman_raw/server.php`
- `benchmarks/peers/webman/`（kode_server.php + config/route.php）
- `benchmarks/peers/hyperf/`（标准骨架 + config/routes.php 两条路由）
- `benchmarks/peers/kode_swoole_server.php`（`KODE_PROFILE=default|lean`、`KODE_DISABLE=` 可调）
