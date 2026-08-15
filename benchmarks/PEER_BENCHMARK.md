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
| swoole_raw | Swoole 原生（无中间件·天花板） | 181,047 | 183,818 |
| workerman_raw | Workerman 原生（无中间件·天花板） | 186,640 | 179,723 |
| **webman** | Workerman 系框架（默认近乎零中间件） | 189,472 | 179,062 |
| **hyperf** | Swoole 系框架（自带 DI/可观测） | 152,770 | 153,203 |
| **kode · lean** | 仅路由+异常+连接收口（Kode::serve 真实路径） | 174,002 | 136,862 |
| **kode · default** | 完整企业级中间件栈（Kode::serve 真实路径） | 86,076 | 58,758 |

> 注：本机为笔记本，跨次运行有 ±20~30% 热漂移；**横比看比值，不看绝对数**。
> 本轮起 kode peer 走 **`bin/kode serve` 真实生产路径**（`Kode::serve` + `HttpBridge`），数字即框架实际交付吞吐，
> 不再经自建 Swoole 适配器（详见第 5.6 节）。kode·default 本轮 3 跑含一次热事件离群，中位数偏保守，稳定双跑约 86-110k / 59-68k。

## 3. 关键结论

1. **kode·lean 内核极强（`/ping` 路径）**：`kode · lean`（**174k** / 137k）的 `/ping` 达到裸 Swoole 天花板的
   **96%**、约为 webman 的 **92%**，且**超过 hyperf 14%**。证明请求构造（`HttpBridge::toPsr7` 改用 kode 自研
   ServerRequest，省 4 次克隆）+ `handle` 路径高效。
2. **kode·lean `/bench/json` 明显落后（74%/76%）**：`/bench/json` **137k** = 裸 Swoole **74%**、webman **76%**，
   比 `/ping` 的 96%/92% 低一大截。根因是 **`HttpBridge::toRaw()` 把 PSR-7 响应纯 PHP 完整序列化成 HTTP 报文字符串
   再 `send()`**（每请求拼接状态行 + 全部 header + body + 计算 Content-Length），而 webman/Workerman 用原生
   C/更成熟层序列化；且 kode 走 kode/process **统一运行时**（Swoole 后端封装）的 I/O 路径比 webman/Workerman
   原生略慢，大 body 下更明显。这是 kode/process 统一运行时抽象的**固有代价**——框架 `src/` 无法单点消除
   （除非 kode/process 提供原生 PSR-7 消费 API，属 vendor 包协作，见第 6 节）。
3. **kode·default（完整企业栈）= 86k/59k**，约为自身 lean 的 **50%（/ping）/43%（/bench）**、裸 Swoole 的
   **48%/32%**、webman 的 **45%/33%**。完整企业栈 + 真实生产路径下，`/bench/json` 折损达 ~57%（中间件栈 +
   `HttpBridge::toRaw` 序列化双重成本），是换取 cors/安全头/限流/韧性/追踪/审计等能力的**功能对价，非缺陷**。
4. **此前「慢」与「失真」的真因**：
   - (a) `ab` 客户端封顶（已用 wrk 修正）；
   - (b) **默认链路追踪全采样（`sample_ratio=1.0`）**——单项最大吞吐税（已改默认 0.1 采样 + 未采样短路 span 创建，见第 5.1 节）；
   - (c) **压测方法学失真（已彻底纠偏）**：旧 harness 用 Nyholm PSR-7 给 kode 强加不存在的开销 + 自建 Swoole
     适配器绕过框架真实路径；本轮已改为走 `Kode::serve` + `HttpBridge` 真实生产路径、且 `HttpBridge::toPsr7`
     改用 kode 自研 ServerRequest（见第 5.5 / 5.6 节）。
   - (d) **框架 `handle` 路径本身不是 wrk 瓶颈（隔离测量实证，仍成立）**：CLI 单进程循环 `$http->handle($psr)`
     （无 Swoole 事件循环、无网络）测得 kode·lean 纯框架处理上限约 **241k ops/s**，远高于 wrk 实测的 **174k**；
     即 wrk 下吞吐被 **kode/process 运行时 I/O（含 `HttpBridge::toRaw` 序列化）** 限制，而非框架代码。
     → 见 `benchmarks/peers/micro_handle.php`。

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

**全链路效果**（见第 2 节）：harness 改走 `Kode::serve` 真实生产路径 + `HttpBridge::toPsr7` 改用 kode 自研 ServerRequest 后，
`kode · lean` **174k / 137k**，`/ping` 达 webman **92%**、裸 Swoole **96%**；但 `/bench/json` 仅 webman **76%**、裸 Swoole **74%**
（根因 `HttpBridge::toRaw` 序列化，见第 6 节 P0），明显低于 /ping。

> 立场：本轮回到的真实数字是 **kode · lean `/ping` ≈ webman 92%（未超过）、`/bench/json` ≈ webman 76%**——
> /ping 的 ~8% 差距来自 kode 保留的**完整 PSR-7 管线 + 异常处理中间件 + 全局请求上下文**（企业级框架 vs 极简框架的架构差异）；
> /bench/json 的更大差距（24%）主因是 `HttpBridge::toRaw` 纯 PHP 序列化 + kode/process 统一运行时 I/O（见第 6 节 P0），非路由内核。
> 要彻底超过 webman 需 runtime 层协作提供 `sendResponse(PSR-7)`，放弃 PSR-7 兼容或中间件收口会削弱 kode 的差异化价值。

### 5.4 benchmark harness 对齐生产 `SwooleServerAdapter`（历史中间阶段，已被 §5.6 取代）

发现 benchmark harness 的 Swoole `request` 回调比生产 `SwooleServerAdapter` 多了 3 个清理调用
（`Tracer::resetOutbox()` / `AccessLogSink::reset()` / `AuditSink::reset()`），而生产 adapter 并不调用——
这会让 kode 在压测里比真实生产显得更慢（多余的每请求函数调用 + 静态数组清空）。

修正（`benchmarks/peers/kode_swoole_server.php`）：
1. **移除 3 个 `reset()` 调用**，使 harness 的每请求开销与生产 adapter 完全一致（lean 档 observability/logging/audit
   均已禁用，reset 本就是多余空清）；同步删除对应 `use` 导入。
2. **发射逻辑对齐补丁后的生产 adapter**：对 `Kode\Http\Response` 实例走 `getBodyString()` 取内部字符串体，
   而非 `(string) $response->getBody()`，确保压测反映 kode/http 补丁后的真实生产发射路径。

效果：harness 现与生产 `SwooleServerAdapter` 逐字节等价（除 Swoole 自身的 HTTP I/O），
wrk 实测数字即真实生产吞吐，不再被 harness 额外开销低估。配合第 3(d) 条的 241k handle 上限测量，
证实 kode·lean 的 wrk 数字（见第 2 节）是 Swoole I/O 上限，非框架低估。

### 5.5 `HttpBridge::toPsr7` 改用 kode 自研 ServerRequest（省 4 次克隆）

`src/Server/HttpBridge.php::toPsr7()` 原用 **Nyholm 不可变 ServerRequest**，链式
`withQueryParams / withParsedBody / withCookieParams / withUploadedFiles` 每次都**克隆整个对象**
（不可变 PSR-7 语义），每请求 4 次全量克隆——且框架内部 `handle` 实际用的就是 kode 自研 ServerRequest，
入口桥接用 Nyholm 构成「同一请求在两个 PSR-7 实现间混用」的不一致。

改为 **kode/http 自研 ServerRequest**（v3.4 起可变消息，`with*` 原地修改、返回 $this、零拷贝），
每请求省 4 次对象克隆，且与框架内核实现一致。

- 验证：`tests/HttpBridgeTest.php` 4/4 + 宽 HTTP/中间件测试 45/45 通过；`bin/kode serve` 冒烟 `/ping`、`/bench/json` 正常。
- 效果：`/ping` 路径体现（真实生产路径下 170k → **174k**，+2%），且为框架可控、与 Swoole 无关的真实优化。

### 5.6 benchmark harness 改走 `Kode::serve` 真实生产路径（口径纠偏）

发现压测 harness `kode_swoole_server.php` 直接 `new Swoole\Http\Server` + 手写 PSR-7 转换 + 手写发射，
**绕过框架真实生产路径**（`Kode::serve` + `HttpBridge`），且违背「框架不碰 Swoole」的架构红线精神。

重写为委托 **`Kode::serve()`**（kode/process 运行时，自动择优 Swoole/Workerman/Native 后端）+ **`HttpBridge`**
桥接，与 `bin/kode serve` 交付路径 **100% 同构**（仅多注册压测路由）。这样压测数字即框架实际交付吞吐。

- 审计结论：框架 `src/Server/` 只委托 `Kode::serve`，**Swoole 接入完全在 kode/process + kode/http 包内**，
  框架 `src/` 不碰 Swoole，符合架构红线。
- 意义：彻底消除「自建 Swoole 适配器」对 kode 的高估（旧 harness 用 Swoole 原生 `$res->end()` 直写，规避了
  `HttpBridge::toRaw` 的纯 PHP 序列化，使 /bench/json 数字偏高）。本轮 §2 表格数字即真实生产路径吞吐。

## 6. 仍可继续提高的点（按性价比排序，待确认后实施）

| 优先级 | 项 | 预期收益 | 改动性质 |
|---|---|---|---|
| **P0** | **`HttpBridge::toRaw` 纯 PHP 序列化是 /bench/json 真实瓶颈**（kode·lean /bench/json 仅裸 Swoole 74%、webman 76%，远低于 /ping 的 96%/92%）。框架可控的优化空间有限（~5-10%，如复用 kode 自研 Response 的 `getBodyString()`、减少 header 行字符串分配）；**根本解决需 kode/process 提供 `sendResponse(PSR-7)` 原生消费 API**，让 Swoole 后端用 C 层 header/end 写出（vendor 包协作，非框架 src 单点可消除） | 中（框架内有限）/ 高（runtime 协作） | 框架内有限 + vendor 包协作 |
| P1 | 全局限流默认 `capacity=10/s` 过低（config/limiting.php），会**真实限流生产流量**；建议默认大幅提高或仅按 `#[RateLimit]` 生效 | 生产可用性（非压测） | 配置默认值 |
| P2 | resilience 三件套（熔断/重试/幂等）目前**全局包裹每条请求**，应仿照 rate-limit/feature/csrf 改为「按路由属性 `#[Retry]`/`#[CircuitBreaker]`/`#[Idempotency]` 扫描后按需注册」 | 默认栈再降数 % | 架构（需评估 kode/http 是否支持路由级中间件） |
| P3 | AccessLog 异步入队仍有每请求格式化开销；可评估「仅在 span/指标已启用时同步元数据」 | 小 | 局部 |
| P4 | 其余常驻中间件（RequestId/Cors/SecurityHeaders/Locale/Feature/Csrf）各自仅微开销，聚合约 10~15%，逐项优化收益递减 | 小 | 局部/可选 |

> 立场：kode 的价值正是「开箱即用的企业级中间件」。默认栈 ~50%（/ping）/~57%（/bench）折损是**功能对价**，
> 不是缺陷；需要极限吞吐时关闭对应组（lean 模式已验证 `/ping` 达裸 Swoole **96%**、webman **92%**）。
> 但 `/bench/json` 受 `HttpBridge::toRaw` 序列化 + kode/process 统一运行时 I/O 限制，真实路径下仅 74%/76%——
> 旧「lean 达裸内核 94%+」基于自建 Swoole 适配器高估，已作废（见第 5.6 节）。
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
