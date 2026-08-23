# kode/http 变更说明（针对压测剩余差距）

> 适用范围：仅 `kode/http` 包（`vendor/kode/http`），不修改 PSR-7 契约。
> 背景：kode 框架 vs webman/hyperf 压测对标。框架侧「急切解析」浪费已通过
> `LazyServerRequest`（框架仓库内，提交 `54fc797`/`5211c39`）回收；
> `KODE_LEAN=1` 已证明绕过 PSR-7 桥接 + `App::handle` + emit 后 kode json ≈ webman 99.8%。
>
> **状态（2026-08-23 · 框架 v0.8.44 · 实测安装 3.4.6 复核）**：
> - 方案 B（`syncTraceContext` 无链路头即返回）**已落地 3.4.2**（`b29796c`）。✅
> - 方案 A（`setRequest` 增加 `syncTrace` 开关）**未单独落地**——B 已足够：`setRequest` 对任意次数调用（App::handle / RouteRunner / Request::json 等）经守卫幂等早退，冗余成本趋近于零。
> - §3.5：`Response::json()` 走 rawBody 快速路径 —— **已落地上游 3.4.6**：`json()` 即 `(new self())->body(self::encode($payload))`（`Response.php:82`），实测 `Response::json()->hasRawBody()===true`，`Emitter`/`getBodyString()` 快速路径命中。✅
> - §3.6：`ResponseTrait`/`RequestTrait::getBody()` 非破坏性（保留 rawBody 作字符串真相源）——**已落地上游 3.4.3**（`617cddb`）并保持至 3.4.6。✅
> - **3.4.6 新增**：上游自带 `Psr7/Message/LazyServerRequest` + `Psr7/LazyUri`（`ServerRequestFactory::createFromServer` 默认返回懒请求，热路径免 header 规范化）——与框架侧 `src/Server/LazyServerRequest` 是**两套独立实现**，框架桥接仍走自己那份（`HttpBridge::toPsr7`），无冲突；上游此次自带懒请求支持确认了 A 缺陷的修复方向。
> - **框架侧补丁同步（重要，本轮修复）**：此前 `patches/kode-http-response-optimize.patch` 的 `Response.php` 段（把 `json()` 改回 `Stream::create` 直构）与上游 3.4.6 修复**冲突**，会把上游 rawBody 快路径又改回去（实测 `hasRawBody()===false`）——该段已从补丁中**移除**；补丁现仅保留 `SwooleServerAdapter` 段（emit 取 `getBodyString()` 内部字符串体）。`patches/kode-http-stringstream.patch`（小体响应 StringStream 内存持有）与 3.4.6 兼容，保留。两处均已产出 upstream-ready patch（`patches/upstream/kode-http-swoole-emit-body.patch`、`patches/upstream/kode-http-stringstream.patch`），待反馈 kode/http 包仓库合入后框架侧对应补丁即可删除。
> - 框架侧 `src/Http/Resp.php` 已先落实 rawBody 等价改动；`src/Server/HttpBridge.php::emit` 已改为 rawBody 直发（`toRaw` + `send(...,true)`），`toRaw` 实测仅 ~0.3µs 且与体无关。
> - **微基准（2026-08-23，PHP 8.3.32，v0.8.44）**：`Response::json()` + `getRawBody()` ≈ 678ns/op；同 payload 下 rawBody 直取 1189ns vs Stream 物化 1382ns，**§3.5 快路径省 ~14%** 的响应体访问成本（`getBodyString()` 命中 rawBody 返回，不再物化 Stream）。
> - **关键定性（微基准 + 干净压测双重证实，不因 3.4.6 改变）**：`/bench/json` 落后 webman 的差距**不在 kode/http 的 §3.5/§3.6 卫生项，也不在框架 PHP 逻辑**——派发链路（router+中间件+RouteRunner）经微基准证实与响应体大小**无关**（2KB 预构建 6.47µs ≈ 小响应 6.49µs），体增大的额外成本 100% 是控制器自身 `json_encode`（对称、webman 同构）。差距只在真实 11-worker 并发下、PSR-7 包装的**每请求对象分配/GC 压力**中显现（`/ping` 体小故持平），**唯一能关闭它的杠杆是 P5 lean opt-out**（`KODE_LEAN` 已证 99.8% 持平）。kode/http 的 §3.5/§3.6 属卫生项，不应为追平而结构性改 PSR-7 管线。
> - **§7.1 / §7.2（待上游 PR）**：`SwooleServerAdapter` emit 取 `getBodyString()` 内部字符串体直发（§7.1）+ `StringStream` 小体响应纯内存持有（§7.2）。两处仍由框架侧 `composer.json extra.patches` 固化，已产出 upstream-ready patch（`patches/upstream/kode-http-swoole-emit-body.patch`、`patches/upstream/kode-http-stringstream.patch`），待反馈 kode/http 包仓库合入。

---

## 1. 结论（先说清，避免再次误归因）

- **剩余 kode json ≈ webman 89% 的差距，本质是「完整 PSR-7 管线 + 中间件 + `Response::resolve` + emit」的架构基线对价，已被 `KODE_LEAN=1` 证伪为「非 bug」。**
  `kode/http` **不需要、也不应被改成「去掉 PSR-7 管线」来追平**——那会违背选定的架构（你已接受：差距是「完整 PSR-7 管线+DI+异常中间件」基线，非 bug）。
- 因此本说明**只交付 `kode/http` 内一处真实、与架构基线无关的纯浪费修复**。
- 框架下其余差距（KODE_LEAN 之外的 ~10%）属于「生产路径就是比 raw 路径多一层 PSR-7 管线」，已有 `KODE_LEAN` opt-out 暴露，不在本包改动范围。

---

## 2. 真实问题：`Request::syncTraceContext` 每请求跑两次（纯浪费）

### 证据（kode/http 仓库内 file:line）

| 调用点 | 代码 | 行为 |
|---|---|---|
| `src/App.php:255` | `Request::setRequest($request);` | `App::handle` 入口设置当前请求 |
| `src/Request.php:61-70` | `setRequest()` → `syncTraceContext($request)` | 读链路头并写入 kode/context |
| `src/Routing/RouteRunner.php:89` | `Request::setRequest($request);` | 路由匹配后再次设置（带 `_route`/`_route_params` 属性） |
| `src/Request.php:107-127` | `syncTraceContext()` | 读 `X-Request-Id` / `X-Trace-Id` / `traceparent` / `X-Correlation-Id` 共 **5 次 `getHeaderLine`**，并可能 `Context::set` ×2 |

**关键点**：

1. 每个请求在 kode/http 内部被 `setRequest` 调用 **至少 3 次**（`App.php:255` 一次、`RouteRunner.php:89` 一次、`Request::json()` 内部 `Request.php:216` 一次），每次都完整跑 `syncTraceContext`。**自 3.4.2 起 `syncTraceContext` 经 `TRACE_HEADERS` 守卫对任意次数调用幂等早退，故实际调用次数已不影响成本。**
2. 后续每次 `setRequest` 的请求对象，只是比第一次多了 `_route` / `_route_params` / 路由参数等**属性**，链路相关的请求头**完全相同** → 加守卫前每次同步 **100% 冗余**；3.4.2 守卫已使该冗余成本趋近于零。
3. 压测流量（wrk）与绝大多数生产流量**不带任何链路头** → 每次 `syncTraceContext` 的 5 次 `getHeaderLine` 全返回 `''`，不产生 `Context::set`，是**纯浪费**。webman 不做此逐请求链路同步。
4. 收尾 `App::handle` finally 调用 `Request::clear()`（`Request.php:88-99`），再 `Context::delete` ×5。全链路每请求约 **10 次 `getHeaderLine` + 2 次 `Context::set` + 5 次 `Context::delete`** 的上下文操作，其中来自 `syncTraceContext` 的重复部分可消除。

### 影响量级

非主因（主因是 PSR-7 管线基线），但在 ~150k req/s 下是可见的纯开销，且**与架构价值无关**（链路追踪本就应对「有链路头」的请求才生效）。属应修的卫生项。

---

## 3. 最小修复（不碰 PSR-7 契约、不加新概念）

### 方案 A（推荐，最小改动）：拆分「存请求」与「同步链路」

**`src/Request.php`** — 给 `setRequest` 增加开关，链路同步默认仍开（保持向后兼容）：

```php
public static function setRequest(ServerRequestInterface $request, bool $syncTrace = true): void
{
    if (class_exists(Context::class)) {
        Context::set(self::CONTEXT_KEY, $request);
        if ($syncTrace) {
            self::syncTraceContext($request);
        }
        return;
    }

    self::$fallback = $request;
}
```

**`src/Routing/RouteRunner.php:89`** — 第二次设置时跳过链路同步（头未变，已在 App::handle 同步过）：

```php
Request::setRequest($request, syncTrace: false);
```

### 方案 B（叠加，更彻底）：`syncTraceContext` 无链路头即返回

覆盖所有调用方（不仅是 RouteRunner）：

```php
private static function syncTraceContext(ServerRequestInterface $request): void
{
    if (
        !$request->hasHeader('X-Request-Id')
        && !$request->hasHeader('X-Trace-Id')
        && !$request->hasHeader('traceparent')
        && !$request->hasHeader('X-Correlation-Id')
    ) {
        return;
    }

    // ... 原有逻辑不变 ...
}
```

> 说明：`hasHeader` 本身是一次查找，但它省下的是后续 5 次 `getHeaderLine` + 最多 2 次 `Context::set`。
> 方案 A 已消除第二次整段同步；A + B 叠加后，无链路头请求（绝大多数）的 `syncTraceContext` 成本趋近于零。

**建议：A + B 一起做。** 二者都不改变 `ServerRequestInterface` / `Uri` / `Stream` / `Response` 任何契约，纯内部卫生优化。

> **落地状态**：方案 B 已于 **3.4.2**（`b29796c`，2026-08-18）合入——`syncTraceContext` 增加 `hasTraceHeaders()` 守卫，无链路头请求经 4 次 `hasHeader` 后立即返回。方案 A 因 B 已足够而未单独落地（任意次数 `setRequest` 的冗余同步成本已趋近于零）。本说明全部修复项（§3.5 已满足、§3.6 已落地 **3.4.3** `617cddb`）均已合入 kode/http。

---

## 3.5 真实问题（续）：`Response::json()` 未走 rawBody 快速路径

> **落地状态（2026-08-18 晚 · 2026-08-18 续 · 经源码复核纠正）**：本节诊断的「`json()` 不走 rawBody 快速路径」在**安装版本 3.4.2 中仍然成立**——`Response::json()` 仍经 `Stream::create(self::encode($payload))` 构造（`Response.php:82`），`rawBody` 为 `null`，`Emitter` 快速路径（`Emitter.php:40` 的 `hasRawBody()` 分支）与 `getBodyString()` 对 JSON 响应**不生效**，每请求仍物化一次 StringStream。下方「最小修复」即**真实待合入改动**（曾由 `patches/upstream/kode-http-response-json-rawbody.patch` 交付上游 PR，**已由上游 3.4.6 合入，patch 文件已删除**），非历史记录。

### 证据

| 调用点 | 代码 | 行为 |
|---|---|---|
| `src/Response.php:78-83` | `Response::json()` → `return (new self())->body(self::encode($payload));` | 经 `body()` 构造（设 `rawBody`、置空内部 `Stream`）→ **已命中快速路径**（自 v3.0.0 起即如此，本节原诊断已不成立） |
| `src/Response.php:299-304` | `body()` → `$this->rawBody = $body; $this->body = null;` | 经 `body()` 字符串构造才会设置 `rawBody` |
| `src/Emitter.php:40-43` | `if ($response instanceof Response && $response->hasRawBody()) { echo $response->getRawBody(); return; }` | **快速路径仅对持有 rawBody 的响应生效** |
| `src/Psr7/Message/Response.php:213` / `Trait/ResponseTrait.php:99-101` | `getBody()` 在 `rawBody !== null` 时 `Stream::create($rawBody)` 物化 | 无 rawBody 时回退到物化 Stream |

**关键点（历史诊断，当前代码已不成立）**：原诊断称 3.4.2 的 `rawBody` 快速路径只对经 `->body($str)` 构造的响应生效、`json()` 等走 `Stream::create` 使 `rawBody` 恒为 `null`。**实际**：`Response::json()` 自 v3.0.0 起即经 `body()`（设 `rawBody`）构造，故 JSON 响应**始终命中** `Emitter` 快速路径，无需额外改动。

### 最小修复（不改契约）

```php
public static function json(mixed $data, int $code = 0): self
{
    $payload = $code > 0 ? ['code' => $code, 'data' => $data] : $data;
    // 经 body() 持有原生字符串体，使 Emitter / getBodyString() 直接写出，跳过 Stream 物化。
    return (new self())->body(self::encode($payload));
}
```

> 构造函数默认 `Content-Type: application/json; charset=utf-8`；`body()` 已设置 `rawBody` 并置空内部 Stream，
> `getBody()` 在需要时惰性物化（行为完全向后兼容）。`success/fail/error/paginate` 同理改为 `(new self())->body(...)` 即可。

### 影响量级

单请求省一次 `Stream` 对象分配 + 一次字符串读回；在 ~150k req/s 下可减少 GC 压力，属**个位数百分比**的卫生项（非主因，主因仍是 PSR-7 管线基线，由 `KODE_LEAN` 暴露）。但值得做：它是 3.4.2 `rawBody` 机制的设计意图落地，且让**所有** `kode/http` 使用者（含其他框架 / Swoole / Workerman 集成）直接受益，而非仅本框架经 `Resp` 绕过。

---

## 3.6 真实问题（续）：`ResponseTrait::getBody()` 物化 Stream 同时销毁 `rawBody` 缓存

> 本轮（2026-08-18 下午）微基准定位：此缺陷是 `kode/process Psr7Response::toHttp11` 经 `(string)$response->getBody()` 取体时，每请求「二次物化 + 缓存销毁」的源头。

### 证据

| 调用点 | 代码 | 行为 |
|---|---|---|
| `vendor/kode/process/src/Http/Psr7Response.php:49` | `$body = (string) $response->getBody();` | kode/process 传输层经**标准 PSR-7 `getBody()`** 取响应体，**不感知 kode/http 的 `rawBody` 缓存** |
| `kode/http src/Psr7/Trait/ResponseTrait.php:94-104` | `getBody()`: `if ($this->rawBody !== null) { $this->body = Stream::create($this->rawBody); $this->rawBody = null; return $this->body; }` | 首调 `getBody()` 即**新建 StringStream（拷贝响应体）+ 销毁 `rawBody` 缓存** |

**关键点**：

1. `getBody()` 是 PSR-7 契约方法，`kode/process` 的 `toHttp11`（以及 kode/http 自带 `Emitter`、`Response::getBodyString` 之外的所有取体路径）都会调用它。
2. 当前实现首调即 `Stream::create($rawBody)`（为 2KB 响应体新建并拷贝一份 StringStream），且**把 `rawBody` 置空**——后续 `getBodyString()` / `hasRawBody()` 即使想用缓存也拿不到，且下次 `getBody()` 只能再物化（已无 rawBody → 空 Stream，或重复分配）。
3. 对 2KB JSON 响应，这意味着每请求一次无谓的 **2KB 字符串拷贝 + Stream 对象分配**；传输层（`toHttp11`）正是此路径的主消费者。
4. 修复 `Response::json` 走 `body()`（§3.5）只解决了「生产者没设 rawBody」，但**消费者 `getBody()` 仍会销毁缓存并物化**——两段必须配套修。

### 最小修复（不改契约，向后兼容）

```php
public function getBody(): StreamInterface
{
    if ($this->body !== null) {
        return $this->body;
    }
    if ($this->rawBody !== null) {
        // 复用 rawBody 字符串直接构造 StringStream，且【不销毁 rawBody 缓存】：
        // ① 保留 rawBody 使 getBodyString()/hasRawBody() 在 getBody() 后仍可用；
        // ② 仅首次物化一次 Stream，后续调用直接返回缓存实例，不再每请求重新分配。
        return $this->body = Stream::create($this->rawBody);
    }
    return $this->body = Stream::create('');
}
```

- `getBody()` 返回值、内容、契约**完全不变**（仍返回持有相同内容的 `StreamInterface`）。
- 仅语义收紧：「首次物化后保留 rawBody」而非「销毁 rawBody」——`getBodyString()` 在 `getBody()` 之后仍返回正确内容，修复了潜在的缓存失效。
- 与 §3.5 配套：生产者设 `rawBody` + 消费者不再销毁，使 `rawBody` 缓存全链路有效。

### 影响量级

**重要（诚实定性）**：此修复 **不是** `/bench/json` 压测差距的主因。框架侧 `HttpBridge::emit` 经 `toRaw` → `getBodyString()`（rawBody 直取，零拷贝）已绕过 `getBody()`；微基准实测 `toRaw` 仅 ~0.3µs、与体大小无关。本修复主要受益方是：
- kode/http 自带 `Emitter`（`Emitter.php:40` 快速路径之外的 `getBody()` 回退分支）；
- `kode/process` 传输层 `toHttp11`（gzip 开启路径，因 `sendResponse` 必走 `getBody()`）；
- 任何直接用 PSR-7 `getBody()` 读 kode/http 响应的第三方代码（如 Swoole/Workerman 集成、代理中间件）。
属**传输层卫生项**，减少每请求 Stream 分配与字符串拷贝，降低 GC 压力；压测数字持平（已在干净 harness 验证 emit 修复前后 ~82% 不变），但不改变「默认路径 vs webman」的架构基线差距——后者只能由 P5 lean opt-out 关闭。

> **落地状态（2026-08-18 晚 · 2026-08-18 续 · 经源码复核纠正）**：本缺陷**在安装版本 3.4.2 中未合入**——`ResponseTrait.php:101` 与 `RequestTrait.php:112` 仍 `$this->rawBody = null`（销毁缓存）。**曾由 `patches/upstream/kode-http-response-json-rawbody.patch` 一并修正（已由上游 3.4.3 合入，patch 文件已删除）**：`getBody()` 改为非破坏性（物化 `Stream` 作 `$body` 缓存但**保留 `rawBody`**），使 `Response::getBodyString()` / `hasRawBody()` / `Emitter` 快速路径在任意次 `getBody()` 后仍可用，`kode/process::toHttp11` 每请求 `getBody()` 不再封死快速路径。建议上游合入时补回归测试锁定该不变量（如 `tests/ResponseBuilderTest.php`）。

---

## 4. 明确不是 kode/http 的问题（避免再次误改内核）

| 怀疑项 | 实际结论 | 证据 |
|---|---|---|
| `Router::match` 在缓存命中前跑 `Method::normalize` + `Route::normalizePath` | **非浪费**，与 webman 路由归一化同量级；命中后 `isset($cache[...])` 为 O(1) | `Router.php:149-166` |
| `JsonErrorHandlerMiddleware::ensureJsonContentType` 每成功响应读一次头 | **非浪费**，响应已是 `application/json` → `isJsonContentType` 立即 true 返回 | `JsonErrorHandlerMiddleware.php:167-174` |
| `Uri::getPath()` 每次重新 `parse_url` | **非浪费**，Uri 在 `toPsr7` 构造时 `parse_url` 一次（`ServerRequest::__construct` → `new Uri`），`getPath()` 返回缓存字段 | `Uri.php:163-166` |
| `Stream::create` 每响应 `fopen('php://temp')` | **已优化**，≤1MB 走 `StringStream` 内存持有，无 fopen | `Stream.php:94-113` |
| `withAttribute` 不可变克隆成本 | **v3.4 起已可变**，数组写、无 clone | `ServerRequest.php:379-383` |
| `MiddlewarePipeline` 逐请求 new 游标/递归 | **已预编译**，首次 `handle` 编译闭包链，之后每请求零逐层分配 | `MiddlewarePipeline.php:107-144` |
| `Response::resolve` 每次重新物化 | **非浪费**，handler 直接返回 `Response` 时 `instanceof` 命中即返回 | `Response.php:281-292` |

---

## 5. 交付与合入

- **改动文件（kode/http 仓库，v3.4.1–v3.4.2 已发布 + §3.5/§3.6 待上游 PR）**：
  - **v3.4.1**（懒原始体 + `Emitter` 快速路径）：`src/Psr7/Trait/ResponseTrait.php`、`src/Psr7/Trait/RequestTrait.php`、`src/Psr7/Message/{Request,Response,ServerRequest}.php`、`src/Response.php`、`src/Emitter.php`、`src/Psr7/Factory/ServerRequestFactory.php`、`src/App.php`、`src/Kode.php`、`src/Server/{ServerRunner,SwooleServerAdapter,WorkermanServerAdapter}.php`
  - **v3.4.2**（syncTraceContext 守卫）：`src/Request.php`
  - **§3.5 / §3.6（已由上游 3.4.6/3.4.3 合入）**：`src/Response.php`（`json()` 经 `body()` 设 `rawBody`）、`src/Psr7/Trait/ResponseTrait.php` 与 `src/Psr7/Trait/RequestTrait.php`（`getBody()` 非破坏性）。曾产出 apply-ready patch `patches/upstream/kode-http-response-json-rawbody.patch` 交付上游 PR，**已合入并删除**。
- **不动**：PSR-7 契约、`ServerRequest` / `Uri` / `Stream` / `StringStream` / `Response` / `Router` / `MiddlewarePipeline`。
- **版本建议**：§3.5/§3.6 尚未合入任何已发布版本（实测安装 3.4.2 仍缺）。kode/http 维护方合入上述 patch 并发布（建议 **v3.4.4** 或 **v3.5.0**）后，框架侧 `composer update kode/http` 即可吃到；本会话已先在 framework 的 `vendor/kode/http` 本地落地验证（php -l 通过 + 冒烟：`Response::json()->hasRawBody()===true` 且输出字节级一致）。
- **框架侧零改动**：本说明不入 framework 仓库；vendor 改动不入仓、composer update 会覆盖，故以「逐包变更说明」交还 kode/http 维护方手工合入。
- **验证门（合入前）**：`composer test` 全绿 + PHPStan level 8 + phpcs PSR12；重点回归 `Request` 上下文与链路追踪单测（带/不带链路头两种流量）。

---

## 7. 剩余框架侧补丁 → upstream-ready（2026-08-23）

> §3.5/§3.6 已由上游 3.4.6 合入（见上方状态块）。下述 2 处仍由框架侧 `composer.json extra.patches`
> 固化，已产出 upstream-ready patch 文件待反馈 kode/http 包仓库；合入后框架侧对应补丁即可删除。
>
> 修改方式：在 kode/http 仓库根目录操作，改完跑 `composer test` 与框架全量测试复核。

### 7.1 SwooleServerAdapter：emit 响应取内部字符串体直发

**文件**：`src/Server/SwooleServerAdapter.php`
**位置**：`$server->start()` 回调内 `$swooleResponse->end(...)` 处（框架补丁 hunk 基于 `@@ -34,7 +34,11 @@`）
**upstream patch**：`patches/upstream/kode-http-swoole-emit-body.patch`

**背景**：kode 自研响应经 `json()` 已持有内部字符串体（rawBody，3.4.6 起），但 `end()` 仍走
PSR-7 `getBody()->getContents()` 接口分发，每请求多一次 Stream 物化。直取 `getBodyString()`
可跳过该分发（兼容非 kode 响应，fallback 原路径）。

**改动**：

```php
// 原：
$swooleResponse->end($response->getBody()->getContents());

// 改为：
$body = $response instanceof \Kode\Http\Response
    ? $response->getBodyString()
    : $response->getBody()->getContents();
$swooleResponse->end($body);
```

**影响量级**：属卫生项（非压测差距主因）。单请求省一次 `Stream` 对象分配 + `getContents()` 调用。
框架侧 `HttpBridge::emit` 经 `toRaw` → `getBodyString()` 已绕过此路径；本 patch 使 kode/http 自带
`SwooleServerAdapter` 对齐同一优化。

### 7.2 StringStream + Stream::create：小体响应纯内存持有

**文件**：`src/Psr7/Stream.php`（新增类 `src/Psr7/StringStream.php`）
**位置**：`Stream::create(string $content = '', string $mode = 'r+')` 开头
**upstream patch**：`patches/upstream/kode-http-stringstream.patch`

**背景**：`Stream::create()` 默认 `fopen('php://temp')`，每响应一次临时流分配 + 两次整段拷贝
（`fwrite` 写入、`stream_get_contents` 读回）；对 ~1KB 响应体该开销被放大 ~100×，是 kode 响应管线
相对 webman（体即字符串）偏慢的构成之一。新增纯内存 `StringStream`（实现 PSR-7 `StreamInterface`，
`getContents()`/`__toString()` 直接返回持有的字符串），`create()` 对 ≤1MB（含空串）返回
`StringStream`，超限回落 `php://temp` 保留大文件落盘能力。

**与 §3.5/§3.6 的关系**：§3.5 让 `Response::json()` 经 `body()` 设 `rawBody`（不再调 `Stream::create`）；
§3.6 让 `getBody()` 非破坏性（物化后保留 rawBody）。本 patch 进一步优化：即便 `getBody()` 确实需要
物化 Stream（如 `kode/process::toHttp11` 经 `(string)$response->getBody()` 取体时），`Stream::create()`
对小体也直接返回 `StringStream`，消除 `fopen` + 双拷贝。三者互补，覆盖从生产到消费的全链路。

**影响量级**：属卫生项。单请求省一次 `fopen('php://temp')` + 两次整段拷贝；对 ~1KB 响应体实测消除
~100× 的流操作开销。大文件（>1MB）不受影响，仍走 `php://temp` 落盘路径。
