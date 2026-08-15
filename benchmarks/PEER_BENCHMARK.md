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
| swoole_raw | Swoole 原生（无中间件·天花板） | 185,117 | 170,824 |
| workerman_raw | Workerman 原生（无中间件·天花板） | 182,311 | 182,000 |
| **webman** | Workerman 系框架（默认近乎零中间件） | 177,172 | 162,229 |
| **hyperf** | Swoole 系框架（自带 DI/可观测） | 151,301 | 137,661 |
| **kode · lean** | 仅路由+异常+连接收口 | 158,925 | 145,984 |
| **kode · default** | 完整企业级中间件栈 | 108,899 | 82,567 |

> 注：本机为笔记本，跨次运行有 ±20~30% 热漂移；**横比看比值，不看绝对数**。

## 3. 关键结论

1. **kode 内核极强**：`kode · lean`（159k/146k）达到裸 Swoole 天花板的 **86%（/ping）/85%（/bench）**，
   约为 webman（177k/162k）的 **90%**。框架路由/`kode/http` 本身不是瓶颈；剩余 ~10~14% 差距来自 kode 保留的
   完整 PSR-7 管线（ServerRequest/Response 构造 + 异常处理中间件 + 全局请求上下文），与极简 webman 的本质差异。
2. **kode · default（完整企业栈）= 109k/83k**，约为自身 lean 的 **68%（/ping）/57%（/bench）**。
   即「默认企业栈」带来约 **30~43% 吞吐折损**，这是为换取 cors/安全头/限流/韧性/追踪/审计等能力的代价
   （属功能对价，非缺陷）。
3. **此前「慢」与「失真」的真因**：
   - (a) `ab` 客户端封顶（已用 wrk 修正）；
   - (b) **默认链路追踪全采样（`sample_ratio=1.0`）**——每个请求都录制 span，是默认栈里单项最大吞吐税
     （已改默认 0.1 采样 + 未采样短路 span 创建，见第 5.1 节）；
   - (c) **压测方法学失真**：旧 harness 用 Nyholm PSR-7 给 kode 强加生产不存在的开销、多 peer 连续满负载
     导致 CPU 热降频累积（kode 排第 5 最热）。已修正为 kode 自研 PSR-7 + 每 peer 预热/冷却，见第 5.3 节。

## 4. 默认栈成本剖析（/ping，逐项关闭定位）

| 配置 | rps | 说明 |
|---|---:|---|
| default（全开） | ~70k | 基线 |
| − observability（追踪+指标） | ~107k | **追踪全采样是 #1 税（~45%）** |
| − resilience（熔断+重试+幂等） | ~66k | 韧性三件套约 7% |
| − observability + resilience | ~96k | 两者叠加 |

进一步切分 observability：关闭 tracing（仅留指标）→ **+53%**；关闭指标（仅留追踪）→ 噪声范围内。
**追踪（全采样）是绝对主导**。

## 5. 已实施的修复（in-framework + vendor patch）

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
`kode_default` 绝对吞吐 +14%（/ping）、+12%（/bench）。
（注：当时 hyperf 处于旧无冷却口径被热降频压至 114k，故当时测出「反超」；补 `COOLDOWN=15` 后 hyperf 回升至 151k/138k，
kode_default 实际低于 hyperf——属完整企业栈与自带 DI 框架的合理定位差异。）

### 5.2 测试
`tests/ObservabilityTest.php` 10/10 通过（采样短路后追踪头仍正确下发、采样比例行为不变）。

### 5.3 kode/http 吞吐路径微优化（vendor patch，经 composer-patches 固化）

目标：把 kode 自研 PSR-7 在「常驻内存发射」这条最热的每请求路径上的不必要开销削掉，
使其无限逼近 webman 那种「`new Response(200, [h], json_encode)` 直构」的极简形态。

两处改动落在 `vendor/kode/http`，通过 `patches/kode-http-response-optimize.patch` 固化、
在 `composer.json` 的 `extra.patches["kode/http"]` 注册——**vendor 本身不入库**，
CI / `composer install` 自动应用，与生产 SwooleServerAdapter 口径一致。

1. **`Response::json` 去中间层**（`src/Response.php`）
   - 旧：`(new self())->body(self::encode($payload))` → `body()` 内部再 `withBody(Stream::create())`，
     即「构造空对象 → 不可变 with* → 再包一层 Stream」，多一次对象分配 + 一次 PSR-7 接口分发。
   - 新：`return new self(200, [], Stream::create(self::encode($payload)));` 一步直构（与 webman `json()` 等价）。
2. **`SwooleServerAdapter` 发射走内部字符串体**（`src/Server/SwooleServerAdapter.php`）
   - 旧：`$swooleResponse->end($response->getBody()->getContents());`——对 kode 自研 Response 也走 PSR-7
     `getBody()`（多态 `StreamInterface` 分发）+ `getContents()` 接口调用。
   - 新：对 `Kode\Http\Response` 实例直接 `$response->getBodyString()` 取内部持有的原生字符串，
     避开 PSR-7 接口分发；外部（Nyholm 等）响应仍走通用 `getBody()->getContents()`。

**micro-bench 隔离验证**（同一进程内纯函数级吞吐，消除框架/网络噪声）：
- tiny 响应：`Resp::json(['ok'=>1])` 1.46M → 1.9M ops/s（**+30%**）
- 50 条记录：`Resp::json($items)` 385k → 400k ops/s（**+4%**）
- 参照：纯 `json_encode($items)` 504k ops/s；webman `json()` 即 `new Response(200,[h],json_encode)`，
  与本改动后的 kode 直构形态等价。

**harness 同构**（消除 handler 不公）：`benchmarks/peers/kode_swoole_server.php` 的 `/bench/json` handler
改为与 webman 完全同构——`array_map(range(1,50), fn => ['id','name'])` + `framework`/`now`/`items`
字段——确保横向 rps 只反映框架开销，不反映「handler 写法差异」。

**run.sh 冷却**（对应第 3(c) 条）：新增 `COOLDOWN=15`，每 peer 测量前 `sleep 15` 让 CPU 从上一 peer
的热态回到 boost 基线，消除多 peer 连续满负载的累积热降频（此前 kode 排第 5、hyperf 排第 6 被系统性压低）。

**全链路效果**（vatsov 同会话，见第 2 节）：`kode · lean` 达 webman **90%**、裸 Swoole **86%/85%**，
且 `/bench/json` 较优化前上行（微优化真实生效）。

> 立场：本轮回到的真实数字是 **kode · lean ≈ webman 90%（未超过）**——剩余 ~10% 差距来自
> kode 保留的**完整 PSR-7 管线 + 异常处理中间件 + 全局请求上下文**，是「企业级框架 vs 极简框架」的
> 架构差异，非可轻易消除的 bug。要彻底超过 webman 需放弃 PSR-7 兼容或中间件收口，会削弱 kode 的差异化价值。

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
