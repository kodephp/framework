# kode/http 包侧调优指引（2026-08-23）

> 配套框架 v0.8.47 · kode/http **3.4.8** · 沙箱 Linux 2 核 / Workerman Select / 无 JIT
>
> 本文档承接 `benchmarks/PEER_BENCHMARK.md` §4.4 沙箱交叉验证的分段打点结论，
> 给出 kode/http 包侧剩余调优候选、已落地修复与本轮新定位的根因。

---

## 1. 本轮新定位根因（已修复）：链路追踪嗅探强制解析

### 1.1 现象

§4.4 打点数据中 `App::handle` 段（21.8µs）里，`route dispatch ≈8.3µs` 的装载
误差约 1.46µs 归属"attr 段"（RouteRunner 三连 `withAttribute` + `setRequest`）。
`withAttribute` 是变异非克隆（`$attributes[$name]=$value; return $this;`），
三次数组写入理论 ≤150ns——剩余 ~1.3µs 的真相在 `Request::setRequest()`。

### 1.2 根因链

```
App::handle → Request::setRequest → syncTraceContext
  → hasTraceHeaders
    ├─ $request->getServerParams()      ← 框架 LazyServerRequest 首访引导构建
    │                                    （method/uri×?/host×2/ip/time/microtime/isSecure）
    ├─ instanceOf Kode\Http\Psr7\Message\LazyServerRequest 早退
    │                                    ← 框架 Lazy 父类不同（ServerRequest），恒 false！
    └─ hasHeader('X-Request-Id') ×4      ← 框架 Lazy hasHeader 无条件 resolveHeaders()
                                           （header 全量规范化，违背懒加载承诺）
```

三个叠加问题：

1. **`getServerParams()` 无条件执行**：kode/http 按"server params 是构造注入的廉价数组"
   设计（包内 `LazyServerRequest` 确如此），但框架侧 `LazyServerRequest` 的
   `getServerParams()` 是**首访引导构建**（Workerman 源 ≈600ns，RAW 源 ≈1.4µs）。
   包的假设与框架实现错位，导致每请求白付一次引导构建。
2. **懒早退 instanceof 错位**：包用 `instanceof Kode\Http\Psr7\Message\LazyServerRequest`
   做守卫，框架的懒类父类不同，判断恒 false，早退永不生效。
3. **hasHeader 循环强制全量规范化**：框架懒类的 `hasHeader()` **不覆写短路**
   （包内懒类有未解析短路），4 次 hasHeader 首次调用即触发
   `resolveHeaders()` 全量 header 规范化——懒加载的"零 header 成本"承诺被彻底击穿。

### 1.3 修复（v3.4.8）

- **新接口 `LazyHeaderAware`**（`Psr7\Message\LazyHeaderAware`）：`isHeadersResolved()` +
  `peekHeader()`（定向读取、不触发任何全量解析）。
- **包内 LazyServerRequest** implements：未解析时先查显式注入缓存、再查 server params
  `HTTP_*` 键，均零解析；已解析退化为 `getHeaderLine`。
- **框架 LazyServerRequest** implements：未解析时委托 `ProcessRequest::rawHeader()`——
  RAW 源对原始报文单次 stripos 定向扫描，其它源退化哈希查找，**不触发**
  `resolveHeaders()`、不触发 `getServerParams()` 引导构建。
- **`hasTraceHeaders` 重排**：LazyAware 且未解析 → 对 4 个链路头定向 peek（命中即 true，
  都没有返回 false，全程零解析）；已解析/非懒请求退化为原 server params + hasHeader 路径。

### 1.4 验证

微基准（`/tmp/hasTrace_first_bench.php`，200k 次，每循环新建请求实例模拟每请求首调）：

| 源 | 旧首调 | 新首调 | 每请求节省 |
|---|---|---|---|
| RAW | 2452 ns | 992 ns | **≈1460 ns（-59%）** |
| Workerman | 2480 ns | 1174 ns | ≈1306 ns（-53%）* |

> \* Workerman 下两路的绝对差受基准顺序影响偏大；真实 Workerman 压测 A/B
> （54,595/54,080/54,635 vs 3.4.7 基线 54,226/55,266）**持平于噪声内**，
> 因为 `host()` 走 `headers()` 缓存，旧路径在 Workerman 源本就 ~600ns 而非 RAW 的 1.4µs。
> **收益定位**：RAW/直连源每请求省 ~1.4µs（≈7%）；Workerman 源结构性修复
> （不再强制 serverParams 引导 + header 全量规范化），对"handler 不读 serverParams"
> 的转发型热路径仍有 ~0.6µs 级纯收益，但对读它（或已解析过 header）的路径基本持平。

功能验证（`/tmp/verify_trace_lazy.php`）：

- 无链路头：`lazyServerParams=null`、`headersResolved=false` 全程保持（零解析）；
- 带 `X-Request-Id`：ctx `REQUEST_ID` 正确命中（peek 定向 + 低频 getHeaderLine）。

全量测试：425 tests / 26428 assertions 全绿；包 `LazyServerRequestTest` 8/31 绿。

### 1.5 状态与注意

- 已合入包仓库 `2718f99`（v3.4.8）；框架侧实现随 v0.8.47。
- 框架侧真机升级：`composer update kode/http`（git 源直连 github，需包仓库已推送）。
- **行为契约**：任何第三方懒请求类若 self-implement lazy header 语义，实现
  `LazyHeaderAware` 即可被守卫识别——这是接口层的稳定扩展点。

---

## 2. 剩余包侧调优候选（已随 v3.4.9 部分落地，更新于 2026-08-23 夜）

> v3.4.9（`docs/kode-http-perf-3.4.9.md`）已实现：`Request::clear` 按需清理（traceWritten）、
> `JsonErrorHandlerMiddleware` 自研响应短路、`Response::isJsonContentType()` 轻量判定。本节仅存低 ROI 项。

### 2.1 RouteRunner::handle 尾部瘦身（低 ROI，可不动）

```php
foreach ($result->params as $name => $value) {
    $request = $request->withAttribute($name, $value);   // 无参路由为 0 次
}
$request = $request
    ->withAttribute('_route', $route)                    // 必调 ×2
    ->withAttribute('_route_params', $result->params);
```

- 无参路由每次 2 次变异写，实测 ≤150ns；1.46µs 大头已由 §1 修复。
- 候选：把 `_route_params` 改为惰性属性（读取时再从 `_route` 取）可省 1 次，收益 <100ns，不建议。
- `dispatchRoute`：`$id = spl_object_id($route)` + 数组命中，已是最小开销。

### 2.2 JsonErrorHandlerMiddleware::ensureJsonContentType（✅ 已随 v3.4.9 实现）

`getHeaderLine('Content-Type')` 对自研响应改为 `Response::isJsonContentType()` 轻量判定
（headerNames 精确映射 + 直接 array 寻址，零 PSR-7 规范化）；且 `process()` 对自研响应整体短路
（默认即 JSON 语义，跳过状态码校验 + 内容类型包装全链）。见 `docs/kode-http-perf-3.4.9.md` §2/§3。

### 2.3 syncTraceContext 其它嗅探（已随 §1 收敛）

获得 §1 修复后，`hasTraceHeaders` 对懒请求不再触碰任何存储；4 个链路头的
stripos 定向扫描（~96ns×4）是剩余的全部成本。若要再压：合并单次搜索
（一条长 needle）反而更慢（未命中要扫整遍报文），**不建议**。

### 2.4 中间件链 / 管道包装

打点显示 pipe chain ≈17µs（含业务载荷 ~6.2µs 双方同付）。跨框架对比
（kode_LEAN json≈100k vs webman≈94.6k，+5.7%）已证明包装层无剩余杠杆，
此处不做结构性改动。

---

## 3. 版本与归档

| 项 | 值 |
|---|---|
| 包仓库 | `composer/http` 分支 `perf-3.4.9`，commit `a0a6d9d`（v3.4.9，**未推送**；v3.4.8 `2718f99` 亦未推送，均待真机 push） |
| 框架 | v0.8.49（`src/Application.php` VERSION；README） |
| 后续调优 | §2.1~§2.3 与 perf-3.4.9 §6 的剩余候选（RouteRunner withAttribute / Context 写删 / toPsr7 协议懒化）已随 v3.4.10 全部落地，见 `docs/kode-http-perf-3.4.10.md`；`patches/upstream/kode-http-3.4.10.patch` 为本轮全部包侧改动的合集 |
| 未推送说明 | github push 由用户在真机执行（HTTPS 无凭证），push 后框架侧 `composer update kode/http` 即同步 |