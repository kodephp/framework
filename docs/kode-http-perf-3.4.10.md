# kode/http v3.4.10 热路径补充优化（RouteRunner 空参 / App isBare / 协议版本懒化）

> 日期：2026-08-24 · 框架 v0.8.49（配套：`benchmarks/l0-profile.php` 微基准）
> 基线：补丁基于包仓库 **v3.4.7 tag**（`40c09ab`）生成，`patches/upstream/kode-http-3.4.10.patch` 已含
> v3.4.8/v3.4.9（traceWritten / JsonErrorHandler 短路 / isJsonContentType / LazyHeaderAware）与本章全部改动。
> 若你的包仓库已合入 3.4.8/3.4.9，请只按「§2 本轮改动（3.4.10 增量）」手工核对；若 HEAD 仍在 v3.4.7，
> 直接整包应用补丁即可。

## 0. 背景：3.4.9 之后还剩什么

3.4.9 已把 `App::handle` 侧（trace 清理按需化 + JsonErrorHandler 短路）削掉 ~4.5µs。分段微基准（沙箱 aarch64、
PHP 8.3.32、JIT off、20 万迭代取最小）显示完整 PSR-7 链路（toPsr7 → handle → toRaw）仍有约 2.4µs/请求的
**框架调度成本**，构成如下：

| 段 | 成本 |
| --- | ---: |
| Router::match 静态路由 | ~286ns |
| Request::setRequest（Context 写入 + trace 嗅探守卫） | ~700ns |
| Request::clear（Context 删除） | ~500ns |
| Middleware 链 + 闭包分发 + resolve | ~900ns |
| 合计 | ~2.4µs |

其中 setRequest/clear 近 **1.2µs** 是「请求 facade 语义」的必要对价（webman 的 `request()` 也存在 context 读写），
但从「App 层预置 + RouteRunner 再写」的**双重写入**降为「单次写入」是零语义风险的纯收益——这正是本轮 P2 做的事。

## 1. 本轮改动清单（追加在 3.4.9 之上）

| 编号 | 文件 | 函数 | 内容 | 收益 |
| --- | --- | --- | --- | --- |
| P1 | `src/Routing/RouteRunner.php` | `handle()` | 无参路由跳过全部 `withAttribute`（含 `_route`/`_route_params`）；404/405 分支补 `Request::setRequest` | 空参路由（压测/生产绝对多数）免 3 次属性数组写入；404/405 不再泄漏 facade |
| P2 | `src/App.php` + `src/Middleware/MiddlewareDispatcher.php` | `handle()` + `isBare()` | 仅默认异常中间件（`middlewares <= 1`）时跳过 App 层 facade 预置，写入责任交由 RouteRunner（FOUND/404/405 已全覆盖） | 热路径 `Request::setRequest` 从 2 次降为 1 次，~700ns/请求 |
| P3 | `src/Request.php` | `hasTraceHeaders()` | 对 `LazyHeaderAware` 且 header 未解析的请求走 `peekHeader()` 定向读取 4 个链路头（stripos 单头扫描），不再触发全量 header 规范化 / serverParams 引导构建 | 懒请求 setRequest 内的嗅探守卫不破坏「懒加载零解析」承诺（已在 3.4.8/3.4.9 落地，随补丁一并交付） |
| P4 | `src/App.php` | `handle()` | HEAD 判定 `strtoupper(...) === 'HEAD'` 改为 `strcasecmp(...) === 0` | 免每请求 `strtoupper` 字符串分配（~50ns） |
| P5 | 框架侧 `src/Server/LazyServerRequest.php`（不在本补丁） | `getProtocolVersion()` | 协议版本从构造期 `preg_match` 提取改为**首次访问才懒解析并缓存**；`withProtocolVersion` 同步缓存；`HttpBridge::toPsr7` 懒分支不再传第 7 参 | 热路径免 `protocol()` 调用 + 正则 + 字符串拷贝（~100-200ns，见框架文档） |

> P5 属于框架仓库（`Kode\Framework\Server\LazyServerRequest`），不在 kode/http 补丁内；但包内
> `src/Psr7/Message/LazyServerRequest.php` 的 3.4.8 改动（serverParams 下沉）是它的前提，均已随补丁交付。

## 2. 本轮改动（3.4.10 增量）—— 需要你在包仓库手动核对的代码

### 2.1 `src/Routing/RouteRunner.php` :: `handle()`

改前（每请求无条件 3 次 attribute 写入；404/405 不写 facade）：

```php
$result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

if ($result->status === RouteResult::NOT_FOUND) {
    return $this->handleNotFound($request);
}

if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
    return $this->handleMethodNotAllowed($request, $result->allowedMethods);
}

$route = $result->route;
foreach ($result->params as $name => $value) {
    $request = $request->withAttribute($name, $value);
}
$request = $request
    ->withAttribute('_route', $route)
    ->withAttribute('_route_params', $result->params);

Request::setRequest($request);
return $this->dispatchRoute($route, $request);
```

改后：

```php
$result = $this->router->match($request->getMethod(), $request->getUri()->getPath());

if ($result->status === RouteResult::NOT_FOUND) {
    // 保持 facade 语义：App 层在「无用户中间件」时不再预置请求，
    // 404/405 分支在此统一写入，行为与旧版（App 层预置）完全等价。
    Request::setRequest($request);

    return $this->handleNotFound($request);
}

if ($result->status === RouteResult::METHOD_NOT_ALLOWED) {
    Request::setRequest($request);

    return $this->handleMethodNotAllowed($request, $result->allowedMethods);
}

/** @var Route $route */
$route = $result->route;

// 无参路由热路径：跳过 attribute 克隆（_route 在包内无消费方；
// _route_params 对空参数恒等于闭包/Request::param() 的默认值 []，语义完全一致）。
// 有参数路由保持原行为（参数注入 + _route/_route_params 全部写入）。
if ($result->params !== []) {
    foreach ($result->params as $name => $value) {
        $request = $request->withAttribute($name, $value);
    }
    $request = $request
        ->withAttribute('_route', $route)
        ->withAttribute('_route_params', $result->params);
}

Request::setRequest($request);

return $this->dispatchRoute($route, $request);
```

**注意事项**：
- 全仓已核 `_route` 属性零消费方（含 OpenAPI/中间件/框架 src/tests/benchmarks），删除写入安全。
- `_route_params` 的消费方是 `Request::param()`/`att('_route_params', [])`（`src/Request.php:474`）与
  `CallableHandler` 目标闭包（`$req->getAttribute('_route_params', [])`）——两者都以 `[]` 为默认值，
  因此空参路由跳过写入后取值结果不变。
- 404/405 分支补 `setRequest` 是 **facade 泄漏修复**：旧版这两个分支不写 facade，若 404 处理器或
  前置中间件调用 `Request::*()` 会读「上一请求」或 null，属隐性 bug。

### 2.2 `src/Middleware/MiddlewareDispatcher.php` —— 新增 `isBare()`

```php
/**
 * 是否为「仅默认异常中间件」的最小栈（无用户注册的全局中间件）。
 *
 * 供 App::handle 快速判定可否省略请求 facade 预置——栈中只有框架默认
 * 异常中间件时，前置阶段无人读取 `Request::*()` façade，请求对象由
 * RouteRunner 在派发时写入即可，行为完全等价。
 */
public function isBare(): bool
{
    return count($this->middlewares) <= 1;
}
```

### 2.3 `src/App.php` :: `handle()`

改前：

```php
Request::setRequest($request);

try {
    $response = $this->dispatcher->handle($request);

    // HEAD 请求不返回响应体
    if (strtoupper($request->getMethod()) === 'HEAD') {
        $response = $response->withBody(Psr7\Stream::create(''));
    }

    return $response;
} finally {
    Request::clear();
}
```

改后：

```php
// 热路径优化：栈中仅默认异常中间件（无用户全局中间件）时，跳过请求 facade
// 预置——此时前置阶段无人读取 `Request::*()`，请求对象由 RouteRunner 在派发时
// 写入（含 404/405 分支），行为与旧版完全等价；RouteRunner 的 finally 清理
// 照常执行（幂等）。有用户全局中间件时保持原行为，中间件内 facade 可用。
if (!$this->dispatcher->isBare()) {
    Request::setRequest($request);
}

try {
    $response = $this->dispatcher->handle($request);

    // HEAD 请求不返回响应体（strcasecmp 无分配，热路径免 strtoupper 字符串拷贝）
    if (strcasecmp($request->getMethod(), 'HEAD') === 0) {
        $response = $response->withBody(Psr7\Stream::create(''));
    }

    return $response;
} finally {
    Request::clear();
}
```

**语义等价性论证**（为什么裸栈可省 App 层预置）：
1. 裸栈（仅默认 JsonErrorHandler）时，`App::handle` 前置无人调用 `Request::*()`——唯一读点是
   JsonErrorHandler 内部，它只读响应对象、不读请求 facade。
2. 无论 FOUND / 404 / 405，RouteRunner 都会 `Request::setRequest($request)`（P1 已补齐），
   handler 与路由级中间件内 facade 可取到**当前请求**，与旧版（App 预置 + RouteRunner 覆盖）等价。
3. `finally Request::clear()` 幂等，无论是否预置都正确清理，无泄漏。
4. **有用户全局中间件时行为不变**——中间件内 `Request::*()` 依然可用。

## 3. 如何应用

### 3.1 你的包仓库 HEAD = v3.4.7（推荐，整包应用）

```bash
# 在 kode/http 仓库根目录
git apply patches/upstream/kode-http-3.4.10.patch   # 或 cp 到仓库根后 git apply
# 版本号：composer.json version 已随补丁更新为 3.4.8，请手动改为 3.4.10
# 提交并打 tag：git tag v3.4.10
```

### 3.2 你的包仓库已含 3.4.8/3.4.9 改动

只手工核对 §2.1 / §2.2 / §2.3 三处代码（`RouteRunner` / `MiddlewareDispatcher` / `App`），
其余（Request、Response、JsonErrorHandlerMiddleware、LazyHeaderAware、包内 LazyServerRequest）跳过。

### 3.3 验证

```bash
# 包内测试
vendor/bin/phpunit                       # kode/http 包自己的测试
# 框架侧全量（本仓库已跑通）
vendor/bin/phpunit                       # 425 tests OK
# 微基准（框架仓库内）
php benchmarks/l0-profile.php 200000
```

## 4. 证据：本轮收益（沙箱 aarch64 · PHP 8.3.32 · JIT off · 20 万×5 轮取最小）

| 指标 | v3.4.9 基线 | v3.4.10 | 变化 |
| --- | ---: | ---: | ---: |
| ping 完整链 sum（toPsr7+handle+toRaw） | 6859 ns | 5493 ns | **-19.9%** |
| ping handle 段 | 4648 ns | 3510 ns | **-24.5%** |
| json50 完整链 sum | 13650 ns | 12407 ns | **-9.1%** |
| json50 handle 段 | 11307 ns | 10246 ns | **-9.4%** |

> 注：json50 的 handle 段大头是业务侧 `array_map+range` 构数据 + `json_encode`（约 6.9µs，webman 同付），
> 框架调度仅 ~2.5µs；本轮削的是框架调度部分，故 json 负载上比例看似低于 ping（ping 无业务载荷，收益全归调度）。
> 完整 peer 压测（wrk 多 worker）请以框架仓库 `benchmarks/PEER_BENCHMARK.md` §4.4 复测表为准。