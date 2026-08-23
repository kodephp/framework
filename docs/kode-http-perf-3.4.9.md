# kode/http v3.4.9 热路径优化（PSR-7 内核链削减）

> 日期：2026-08-23 夜 · 框架 v0.8.48 · kode/http **v3.4.9**（分支 `perf-3.4.9`，commit `a0a6d9d`）
> 配套：`benchmarks/PEER_BENCHMARK.md` §4.4（本机 aarch64 复测表）/ §5 结论 6

## 0. 背景：钱花在哪里

上一轮（v3.4.8 `LazyHeaderAware`）根治了「链路追踪嗅探强制解析」后，每请求热路径（x86 沙箱打点基准，合计 ~27.8µs）分布为：

| 段 | 成本 | 已处理 |
| --- | ---: | --- |
| toPsr7（懒请求构造） | 2.44µs | v3.4.8 serverParams 下沉 + LazyServerRequest |
| handle（App 管线） | 12.01µs | **本轮：opt1+opt2 削 ~4.5µs** |
| └ app（Context setRequest+clear 写删 ~2.4µs、errMW 判定 ~2.2µs） | 11.51µs | opt1（traceWritten）、opt2（errMW 短路） |
| └ 业务 json_encode | ~5µs | 不可省（webman 同付） |
| emit（toRaw 0.65 + conn->send 7.7） | 8.08µs | conn->send 为 Workerman 固有 |

本轮只动 **kode/http 包**（框架侧已无杠杆），共 3 个源文件 + 4 个新单测。

## 1. 优化一：`Request::clear()` 按需清理链路上下文（opt1，-~1µs/请求）

**改什么**（`src/Request.php`）：

```php
/** 本轮请求是否已写入链路追踪上下文（供 clear 按需清理，热路径省 4 次 Context::delete） */
private static bool $traceWritten = false;
```

- `clear()`：仅当 `self::$traceWritten === true` 才循环 `Context::delete(self::TRACE_KEYS)` 并复位标志；绝大多数请求（压测/生产）不携带链路头，直接跳过 4 次 delete（每次含执行单元解析 + WeakMap 查找，合计 ~1µs/请求）。
- `syncTraceContext()`：三处写入（REQUEST_ID / TRACE_ID / CORRELATION_ID）命中时置 `self::$traceWritten = true`，保证「写必清、不写不清」，上下文生命周期语义不变。

**证据**：x86 网络 A/B（wrk -t4 -c60，干净基线）：/ping 77,993 → **79,889（+2.4%）**、/bench/json 51,616 → **53,784（+4.2%）**；本机 aarch64 微基准 handle 段 134.8 → **130.3µs（-4.5µs，-3.3%）**。

## 2. 优化二：`JsonErrorHandlerMiddleware` 自研响应短路（opt2，-~2µs/请求）

**改什么**（`src/Middleware/JsonErrorHandlerMiddleware.php` `process()`）：

```php
try {
    $response = $handler->handle($request);
} catch (\Throwable $e) {
    return $this->handleException($e, $request);
}

// 热路径短路：Kode 自研响应构造默认即 application/json，且状态码语义由
// `status()` 显式维护——无需再校验状态码与 Content-Type（省 getStatusCode +
// handleErrorResponse/ensureJsonContentType 全链解释开销）。
// 仅对非自研 PSR-7 响应（用户手工 new 或第三方中间件产物）保留完整包装逻辑。
if ($response instanceof \Kode\Http\Response) {
    return $response;
}
if ($response->getStatusCode() >= 400) {
    return $this->handleErrorResponse($response);
}
return $this->ensureJsonContentType($response);
```

**语义说明**：自研 `Response` 构造默认注入 `Content-Type: application/json; charset=utf-8`（`src/Response.php:54`），`status()`/`json()` 等工厂显式维护状态码语义——故 4xx/5xx 时内容类型**必然合法**，包裹逻辑（校验状态码 + 兜底 JSON 类型）对自研响应是纯冗余。第三方 PSR-7 响应保留原包装，行为零改变。

## 3. 优化三：`Response::isJsonContentType()` 轻量判定（opt3）

**改什么**（`src/Response.php`，新增公共方法）：

```php
public function isJsonContentType(): bool
{
    $key = $this->headerNames['content-type'] ?? null;
    if ($key === null) {
        return false;
    }
    $ct = $this->headers[$key][0] ?? '';
    return str_contains(strtolower($ct), 'application/json');
}
```

- `headerNames`（小写→原始大小写）映射与 `getHeaderLine()` 语义**完全一致**——构造传 `Content-Type` 或 `content-type` 均命中，不会漏判；无 CT 时自研构造默认注入 JSON → 返回 true（正确）。
- 直接 array 寻址，跳过 PSR-7 的 normalizeHeaderName 全表遍历 + implode（热路径 ~1-2µs/请求）。
- `Middleware::isJsonContentType()` 私有方法对自研响应改为调本方法，非自研仍走 `getHeaderLine` 原路径。

## 4. A/B 证据汇总

| 口径 | 基线 | 优化后 | 变化 |
| --- | --- | ---: | ---: |
| x86 网络 /ping（wrk4t60 干净基线） | 77,993 | 79,889 | **+2.4%** |
| x86 网络 /bench/json | 51,616 | 53,784 | **+4.2%** |
| 本机微基准 handle 段（min-of-3） | 134.8µs | 130.3µs | **-4.5µs（-3.3%）** |
| 本机 kode_L0 /ping（4 轮中位） | — | 83,173 | 62.4% of raw |
| 本机 kode_L0 /bench/json（4 轮中位） | — | **54,919** | 57.0% of webman |

> 网络口径对 <2µs 级改动噪声 ±3-4%，故单项归因以微基准为准、整体方向以网络 A/B 为准；L0 绝对值（本机 54.9k / x86 54.7k）跨机器稳定，证明无回归。

## 5. 测试与兼容性

- kode/http 包：**248 tests / 505 assertions 全绿**（新增 4 例 `isJsonContentType`：默认 JSON / 显式 text/html false / 小写头键命中 / 无显式头默认 JSON）。
- 框架全量：**425 tests / 26428 assertions / 1 skipped 全绿**（vendor 已同步 v3.4.9 同源文件）。
- 行为不变量：带 `X-Request-Id` 请求上下文正确写入并清理（`traceWritten` 写必清）；非自研 PSR-7 响应仍完整走错误包装；`isJsonContentType` 与 `getHeaderLine` 判定结果逐例一致。

## 6. 剩余候选（按 ROI，供下一轮包侧调优）

| # | 项 | 预计收益 | 备注 |
| --- | --- | ---: | --- |
| A | `RouteRunner::handle` 尾部 `_route` + `_route_params` 两次 withAttribute（无参路由） | ≤150ns | 已判低 ROI，可不动 |
| B | `Response::resolve` 双次包装去一层（resolve 内重复解析已构造的响应） | ~0.5µs | 需查调用链确认无契约依赖 |
| C | `App::handle` 中 `Context` 重复 setRequest + clear 写删降级为「按需写」 | ~1-2µs | 语义面广，需逐中间件核对 |
| D | toPsr7 残余 `parseLine` 首次 + `Uri` 构造降本 | ~0.5-1µs | 与 kode/process-http 侧共享，跨包改动 |

## 7. 版本与推送

| 项 | 值 |
| --- | --- |
| 包仓库 | `composer/http` 分支 `perf-3.4.9`，commit `a0a6d9d`（**未推送**，HTTPS 无凭证，待真机 push） |
| 框架 | v0.8.48（`src/Application.php` VERSION + README，已推送 github master） |
| vendor | 已手工 cp 同步 v3.4.9 同源三文件（composer 会在真机 `composer update kode/http` 后正式对齐） |
| 推送后 | `composer update kode/http` 拉取 v3.4.9 即可，无需 patch |