# 健壮性设计（Robustness）

> 框架以「薄壳 + 防御性边界」为原则：不重复造 kode 生态的能力，但在**引导期、错误路径、CLI 入口**
> 这些「一旦出错就会裸奔」的关键边界上做防御，确保任何意外都不会泄漏原始堆栈、空白 500 或难以排查的启动失败。

---

## 1. 错误响应永不失明（ExceptionMiddleware）

`ExceptionMiddleware` 是最内层异常屏障，把下游未处理异常交给 `kode/exception` 统一格式化为
结构化 JSON。它有两层防御：

1. **渲染器自身失败也安全**：若 `ExceptionManager::respond()` 抛错（循环依赖、内部异常），
   `toResponse()` 捕获后回退为最小安全响应：

   ```json
   { "message": "Internal Server Error", "trace_id": "..." }
   ```

   状态码 500，并记录原始错误到日志。错误处理器**绝不会**以「裸 PHP 错误 / 空白页」结束。

2. **校验异常特例**：`ValidationException` 转为 422，含字段级错误明细，不走通用渲染。

```php
$mw->process($req, $handler); // handler 抛任何 Throwable → 结构化 JSON，而非裸栈
```

---

## 2. 链路追踪外层不可失败（TraceMiddleware）

`TraceMiddleware` 位于全局管线**最外层**，负责为每个响应（含异常响应）附加 `traceparent` /
`X-Trace-Id` / `X-Span-Id`。它同样做了防御：

- `TraceContext::ensure()` 失败 → 仅记录 warning 并降级为「无链路头」继续处理，不阻断请求；
- `responseHeaders()` 失败 → 跳过链路头，业务响应照常返回。

这样即便追踪子系统异常，错误管道也不会被「外层中间件抛错」击穿而丢失结构化错误响应。

---

## 3. .env 解析健壮性（EnvLoader）

`.env` 由 `Kode\Framework\Support\EnvLoader` 解析，容忍常见写法：

| 写法 | 结果 |
| --- | --- |
| `KEY=val` | `KEY=val` |
| `export KEY=val` | `KEY=val`（兼容 shell 导出） |
| `KEY="a=b c"` | `KEY=a=b c`（去引号） |
| `KEY=val # 注释` | `KEY=val`（行内注释，仅 `#` 前有空白时生效） |
| `URL=http://x#y` | `URL=http://x#y`（值中 `#` 前无空白，保留） |
| `# 注释` / 空行 / `NO=` 缺等号 | 跳过 |
| `=val` / `export =val` | 跳过（空 key） |
| `KEY=` | `KEY=`（空字符串） |
| 文件首行 UTF-8 BOM | 自动剥离 |

加载策略：**不覆盖**已通过真实环境变量（`$_SERVER`/`$_ENV`）注入的值，遵循 12-factor
（真实环境优先于 `.env` 文件）。

---

## 4. CLI 启动失败优雅退出（bin/kode）

所有命令（`console` / `cron` / `schedule:list` / `serve`）在执行 `Application::make()` 或
服务启动时的任何未捕获异常，都会被 `KodeCli::fail()` 收口：

```text
❌ RuntimeException: 应用启动失败（/path/to/app）：Class "App\No\Such\Provider" not found
```

- 以**非 0 退出码（1）**结束，便于 CI / 编排系统感知失败；
- `APP_DEBUG=true` 时附上完整堆栈便于本地排查；
- 不会把原始 PHP 堆栈直接抛给用户。

---

## 5. 容器未启动守卫（resolve 助手）

在 `Application::make()` 完成之前调用 `resolve()` / `logger()` 等助手，不再触发
「call to a member function make() on null」的晦涩致命错误，而是抛出清晰提示：

```text
RuntimeException: 服务容器尚未启动，无法解析 [cache]。请确保在 Application::make() 引导完成后再调用助手函数。
```

---

## 6. 启动失败清晰化（Application::bootstrap）

`CoreApp::boot()` 抛出的任何错误都会被包成带上下文的 `RuntimeException`，保留原始异常为
`previous` 以便追溯：

```text
RuntimeException: 应用启动失败（/path/to/app）：<原始原因>
```

配合第 4 节的 CLI 收口，开发者能立即定位是「配置缺失 / provider 类不存在 / 路径错误」等问题。

---

## 7. 请求级数据库事务（TransactionMiddleware）

对**写请求**（POST / PUT / PATCH / DELETE）自动开启一个数据库事务，控制器内所有写操作都在
同一事务上下文中；正常返回则提交，抛异常则回滚。由此把企业级不变量
「**一次 HTTP 请求 = 一个原子工作单元**」落到框架层，避免「写了 A 成功、写 B 失败、数据半成品」。

```php
// config/database.php
'auto_transaction' => env('DB_AUTO_TRANSACTION', false),   // 默认关闭，按需开启
'transaction_skip_paths' => ['/health', '/metrics', '/ping'],
```

```php
// 写请求进入 → 框架自动开事务
$id = transaction(function () {        // 助手，委托 db()->transaction()
    $user = User::create([...]);
    $user->profile()->create([...]);   // 任一步失败，整段回滚
    return $user->id;
});
```

要点：
- **默认关闭**，需 `database.auto_transaction = true` 才生效（避免对只读 / 心跳 / 探针无谓开事务）；
- **仅作用于写方法**，GET / HEAD / OPTIONS 零开销放行；
- **跳过路径**：`transaction_skip_paths` 中的探针不开启事务；
- **异常透传**：回滚后仍将异常抛出，交由最外层 `ExceptionMiddleware` 产出统一结构化错误响应
  （含 `trace_id`），错误形态与无事务时完全一致；
- **真正原子（kode/database 1.15.5+）**：该版本起 `Db::getConnection()` 缓存同一连接名的 PDO
  连接，begin / insert / commit / rollback 落在同一连接上，事务原子性成立；事务进行中读操作
  强制走主库（写连接），避免读到未提交数据被读写分离路由到从库；
- **嵌套事务**：由 `kode/database` 的 `transactionDepth` 跟踪 + PDO savepoint 自然处理，不破坏
  外层边界；
- 也可用 `transaction()` 助手在任意位置显式开事务（见 `src/Support/helpers.php`）。

> **历史缺口已修复**：早期 `kode/database` 的 `Db::getConnection()` 每次调用都新建 PDO 连接，
> 跨调用的事务不生效（begin / insert / commit 在不同连接上）。该缺口已在 **1.15.5** 通过
> `Db::$connectionCache` 连接池修复。框架侧无需任何绕过代码——升级依赖后即自动获得完整原子性。
> 真实原子性（回滚丢弃写入 / 提交持久化 / 事务内读己写）由 `tests/ConnectionLifecycleTest.php`
> 基于临时 sqlite 覆盖。

---

## 8. 坏 JSON 显式 400（JsonBodyMiddleware）

`kode/http` 的 `Request::post()` 在 body 非合法 JSON 时**静默返回空数组**，导致下游拿到空数据后
往往以 422 / 500 收场，且错误信息指向「字段缺失」而非真正的「格式错误」。

框架新增 `JsonBodyMiddleware`，在请求入口主动校验：当 `Content-Type` 声明 `application/json`
（或 `+json` 后缀）且 body 非空却 `!json_validate` 时，直接返回 **400 + 明确错误**，把「格式错误」
这一输入问题在最早、最明确的环节拦截下来。

```php
// config/http.php
'json_strict' => env('HTTP_JSON_STRICT', false),   // 默认关闭，按需开启
'json_skip_paths' => ['/health', '/metrics', '/ping'],
```

```json
// 坏 JSON 响应示例
{ "message": "请求体不是合法的 JSON", "error": "invalid_json" }
```

要点：
- **默认关闭**，需 `http.json_strict = true` 才生效（避免影响以表单 / 纯文本为 body 的既有接口）；
- 仅对显式声明 JSON 的 `Content-Type` 生效；表单、纯文本、空 body 一律放行，不干扰其它合法用法；
- GET / HEAD / OPTIONS 等无 body 语义的方法天然放行。

---

## 9. 连接生命周期收口（ConnectionCleanupMiddleware）

`kode/database` 1.15.5+ 缓存连接池后，连接属于进程级静态态，常驻进程（Swoole / Workerman /
多进程 prefork worker）请求间复用同一连接以获得性能。但这要求框架在**请求结束**时做连接级收口，
否则出现两类健壮性风险：

1. **事务泄漏**：控制器手动 `Db::beginTransaction()` 后抛异常却未回滚，残留事务绑在缓存连接上
   跨请求延续——下一个请求可能读到未提交数据、或被持久锁阻塞；
2. **跨请求连接污染**：单测 / CLI 之间若不释放缓存连接，会互相串数据。

框架新增 `ConnectionCleanupMiddleware`，注册在全局链**最外层**，无论请求成功还是异常（`finally`
保证）都在响应产出后收口：

```php
// config/database.php
'leak_rollback'        => env('DB_LEAK_ROLLBACK', true),         // 残留事务强制回滚（默认开）
'release_per_request'  => env('DB_RELEASE_PER_REQUEST', false),  // 响应后释放缓存连接（默认关）
```

收口逻辑：
- 若 `Db::inTransaction()` 仍为真（检测到泄漏事务）→ 强制 `Db::rollback()` + 记告警，杜绝跨请求续命；
- 若 `release_per_request = true` → 调用 `Db::disconnect()` 释放全部缓存连接
  （适合单测 / CLI / 连接易失效场景；常驻 API 服务默认关，保留连接池复用性能）。

要点：
- **零配置默认安全**：`leak_rollback` 默认开（事务绝不跨请求），`release_per_request` 默认关
  （保留连接池性能，由 `kode/database` 的缓存承担复用）；
- **只做防御网**：正常路径下 `TransactionMiddleware` 已 commit / rollback，`Db::inTransaction()`
  为假，本中间件不触碰任何事务；仅捕获「绕过框架事务的手动 begin 残留」；
- **绝不改变响应**：收口失败（连接已断开等）被静默吞掉，原始响应 / 异常照常向外传递；
- 编排契约（何时回滚 / 何时释放）由 `tests/ConnectionLifecycleTest.php` 的 spy 覆盖。

---

## 10. 启动自检：已装包却未接线 Provider 必告警（Provider Coverage）

薄壳框架的一个隐蔽哑火点是——**包装了某个 kode/* 能力，但对应的 ServiceProvider 没接进
`providers` 列表**（硬编码 `$defaults` 或 `config/app.php`）。其结果不是报错，而是能力「静默失接」：
装了包、配置写了，运行时却找不到服务，排障时极难定位。

`Application::bootstrap()` 在用户引导之后新增 `checkProviderCoverage()`，把这种哑火变成**显式 WARNING**：

```php
// config/app.php
'provider_self_check' => env('APP_PROVIDER_SELF_CHECK', true),  // 默认开，可整体关闭
```

- 对照 `CAPABILITY_PROVIDERS`（kode/cache、kode/database、kode/queue、kode/messaging、
  kode/scheduling、kode/session、kode/aop、kode/parallel）→ 期望 Provider 映射；
- 仅对已安装（`vendor/<package>` 存在）的包检查；未安装的跳过（不误报）；
- 若该包装了、但 `providers()` 列表里没有对应 Provider → `logger()->warning(...)` 明确点名
  缺失的包与期望的 Provider，提示去 `config/app.php` 登记或实现对应 Provider；
- v0.8.6 起 session / aop / parallel 均已补齐对应 Provider（见 §11），自检不再对此三类告警。

要点：
- **只告警不阻断**：自检失败不影响启动（WARNING 写入日志，部署/排障第一时间可见）；
- **可被 `app.provider_self_check = false` 关闭**：确有意的精简场景可跳过；
- **新增能力即登记**：后续薄壳接入新 kode/* 包时，在 `CAPABILITY_PROVIDERS` 追加一行即可
  纳入自检，避免再次陷入「装了却没接」的哑火。

> 配合本次修复的另外两处「薄壳失接」：队列消费进程（`queue:work` + `QueueServiceProvider`
> 的 `HandlerResolver` 单例，P0-1）、调度器生命周期接线（`SchedulingServiceProvider` 把
> `ScheduleDispatcher` 接进 `providers` 并按 `config/schedule.php` 的 `paths` 自动发现
> `#[Cron]` 任务，P0-2）。三者共同消除「写了异步任务/定时任务却永不运行」的最大坑。

---

## 11. 已装能力全部接线（v0.8.6：session / aop / parallel）

v0.8.5 的启动自检暴露出三类「装了包却没接」的哑火：kode/session、kode/aop、kode/parallel。
v0.8.6 补齐了对应的 ServiceProvider，使框架对**已安装的全部 kode/* 能力**都完成接线，
自检不再对这三类误报告警。

- **kode/session** → `SessionServiceProvider` + `HttpServiceProvider` 注册 `SessionMiddleware`
  （auto_start + 响应时落盘 + 概率 GC）。`session()` 助手读写当前请求会话。
  - 配置陷阱已规避：`config/session.php` 在配置加载期 `app()` 尚未就绪，`storage_path()` 会退化成
    相对路径，导致 `FileDriver` 锁目录解析失败（500）。故 file 存储路径改为**在 Provider 注册期**
    解析为绝对路径并 `mkdir`；且**不写顶层 `path` 键**（Cookie 路径默认 `/`），避免 `kode/session`
    把整个 session 配置透传给驱动工厂时顶层 `path` 覆盖 `drivers.file.path`。
- **kode/aop** → `AopServiceProvider` 启动 AOP 内核，并按 `config/aop.php` 的 `paths` 自动发现
  `#[Aspect]`（镜像 `TaskScanner` 的 kode/attributes 扫描范式）。`aop()` 助手返回内核 /
  `diagnostics()`。`app/bootstrap.php` 中旧的手动 `Aop::register` 已移除（改由自动发现统一接管，避免重复注册）。
- **kode/parallel** → `ParallelServiceProvider` 探测运行时可用性（ZTS + 扩展），把 `parallel.available`
  / `parallel.bootstrap` 存入容器作为单一事实源；非 ZTS 环境优雅报 `available=false`、`parallel()`
  助手自动回退 sync 引擎（API 一致、不报错）。

三个能力的接线均遵循既有 Provider 范式，未改动任何 kode/* 包源码（纯薄壳委托）。

---

## 12. 每请求上下文隔离：租户 / 追踪绝不跨请求串扰（v0.8.7 多租户原语）

生产级框架的隐性坑：**请求级状态若写进全局/单例上下文，会在常驻 Worker（Workerman/Swoole）
里跨请求串扰**。本框架与 kode/http 均未在请求外层包 `Context::run`，`TraceContext` 也是直接
`Context::set`（无 scope），因此任何「按请求设置、期望请求内可见」的状态都必须自带隔离。

多租户原语（`src/Tenant/`）正是按此约束设计的：

- `TenantMiddleware` 用 `Context::runWith(['tenant.id' => $id], fn() => $handler->handle($request))`
  建立**每请求隔离 scope**——下游中间件 / 控制器 / `tenant()` 助手在该 scope 内可见当前租户；
  请求结束 scope 自动出栈，下一个请求重新解析，**绝不串扰**（与 P1 时期 SessionManager 单例
  残留会话是同一类坑，这里在架构层规避）。
- 框架只提供「租户上下文原语」，**不绑定任何存储隔离策略**（库 / schema / 判别列由应用层自行实现）。
  理由：具体租户存储是强业务决策，框架硬塞会变成错误的强约束，违背薄壳原则。`TenantResolver`
  接口 + 内置 `Header` / `Subdomain` 策略 + `tenant()` 助手，应用注入自定义解析器即可。
- 启用：`config/tenant.php` 的 `enabled=true` + `resolver`（'header' | 'subdomain' | 自定义
  `TenantResolver` 类名）+ 可选 `default` 回退；`HttpServiceProvider::boot()` 按开关把
  `TenantMiddleware` 接进 HTTP 管道。

> 通用规则：凡是在请求内「设置、期望请求内读到」的上下文（租户、请求 ID、trace、租户化缓存键前缀等），
> 一律走 `kode/context` 的 request/协程 scope（`Context::runWith` / `Context::with`），不要写进
> 单例或全局静态（除非该单例在每次请求入口显式 reset）。

---

## 设计取舍

- **不安装全局 `set_exception_handler`**：kode/process、kode/core 运行时自身接管进程级异常，
  框架只在 HTTP 中间件与 CLI 入口做「边界级」防御，避免与运行时冲突（详见
  `ExceptionServiceProvider` 注释）。
- **错误响应形态 100% 由 kode/exception 决定**：框架只负责「兜底不裸奔」，不重新发明错误渲染。

---

## 13. 优雅停机 drain：在途请求排空 + 退出前收尾（v0.8.8）

常驻多进程服务（kode/process master-worker）在收到 SIGTERM/SIGINT 时必须「先排空在途请求、
再退出」，否则 k8s/LB 摘流瞬间正在处理的请求会 502。本框架按**薄壳边界**补齐业务层两件事，
信号/进程编排仍完全委托 kode/process：

- kode/process 已负责底层 drain：收到信号 → 停收新连接（移除监听套接字）→ HTTP/2 发 GOAWAY →
  用 `gracefulShutdownTimeout` 计时器等待在途连接自然关闭，超时强制退出 → 触发 `workerStop` 事件。
  框架**不重写**这部分（避免与运行时冲突，也符合薄壳原则）。

框架薄壳层新增（`src/Server/GracefulShutdown.php` + `GracefulShutdownServiceProvider`）：

- **在途请求计数**：`HttpServer` 在每个请求处理外包 `GracefulShutdown::track()`，进出各 ±1。
  多进程下每个 worker 是独立进程、Application 各自重建，实例天然隔离，不会跨进程串扰。
  计数可用于观测排空进度（`graceful()->inFlight()` / `stats()` / 探针 / metrics）。
- **可配置宽限**：`config/server.php` 的 `graceful_shutdown_timeout`（默认 30s，env
  `SERVER_GRACE_PERIOD`）传给 `Kode::serve` 的 `gracefulShutdownTimeout` 选项。建议
  `P99 长事务耗时 < 此值 << k8s terminationGracePeriodSeconds`，给 LB 摘流 + 进程退出余量。
- **退出前收尾注册表**：`WorkerStopping` 事件触发 `GracefulShutdown::shutdown()`，按注册顺序执行清理回调
  （默认：flush 队列连接 `queue()->close()`、断开 DB 连接 `db()->disconnect()`；均按「能力是否就绪」
  门控 + `method_exists` 守卫，未安装/未绑定则静默跳过）。清理回调**异常逐个吞掉**，绝不因收尾动作
  反向拖垮停机；`shutdown()` 幂等（重复触发不重复执行）。
- **业务可扩展**：自行 `graceful()->registerCleanup(fn () => ...)` 追加收尾（注册中心下线、指标落盘、
  文件锁释放等），或在 `config/event.php` 的 `listeners` 里追加 `WorkerStopping` 监听器。

验证（e2e 冒烟，grace=3s、worker=1）：
- `GET /` → 200；`kill -TERM` 后进程在 ≈3.3s 干净退出（与宽限一致）。
- 中途发起 1.5s 慢请求再 `SIGTERM`：请求仍返回 200（`{"slow":"done","inflight":1}`），证明在途请求
  被正常排空而非被切断，且 `inflight` 计数实时正确。

> 设计立场：优雅停机的「信号/连接 draining」交给 kode/process；框架只负责「业务层在途计数 +
> 退出前清理」这一薄薄一层，二者职责边界清晰，互不重写。

## 14. Feature Flags：声明式功能开关 + 灰度（v0.8.9）

生产级发布需要「功能开关 / 灰度 / A-B 实验」能力：未验证的功能默认关闭、按百分比灰度放量、
按用户/租户稳定分桶、关闭时路由优雅 404/403。本框架以**原生薄实现**提供（无 kode 包对应），
复用已验证的「属性路由 + 中间件」范式（与限流同构）：

新增组件：

- `config/feature.php`：`enabled` 总开关、`default` 未配置回落、以及 `flags` 声明（`enabled` /
  `rollout` 0-100 / `description`）。
- `src/Feature/FeatureManager.php`：判定核心。`isEnabled(name, key)` 模型：
  - 动态 resolver 优先（`registerResolver()` 接入 DB/Redis/配置中心/租户覆盖）；
  - 未配置 flag → 回落 `feature.default`（默认 false，即「默认关闭、显式开启」）；
  - `enabled=false` 直接关（无视 rollout）；`enabled=true` 结合 `rollout` 灰度：
    `rollout>=100` 全量、`rollout<=0` 无、`0<rollout<100` 按 `crc32(name:key)%100 < rollout` 稳定分桶。
  - `status()/all()` 返回含分桶结果的快照，便于排查灰度命中。
- `src/Feature/Attributes/Feature.php`：`#[Feature('flag', fallback: 404)]`，可用在控制器类（对所有方法）
  或方法（覆盖类级）。
- `src/Feature/FeatureAttributeReader.php` + `FeatureRegistry.php`：反射读取 + 路由→flag 登记表
  （`spl_object_id` 零侵入，与限流同范式）。
- `src/Feature/Middleware/FeatureMiddleware.php`：全局中间件，对命中 `#[Feature]` 的路由做开关校验，
  关闭时返回 `fallback`（默认 404，可声明 403）；未声明路由直接放行。分桶键 `X-User-Id →
  X-Tenant-Id → 客户端 IP`，保证同一用户/租户在灰度窗口内命中稳定、不抖动。
- `feature()` 助手：`feature('x')` 即时判定；`feature('x','user:42')` 按 key 分桶；`feature()` 取管理器。

接线（`FeatureServiceProvider`，已接入 `Application::$defaults`）：

- `ControllerScanner` 在属性路由注册时给每条路由打 `#[Feature]` 标（与限流打标同一处）；
- `HttpServiceProvider::boot()` 在 `feature.enabled`（默认 true）下注册 `FeatureMiddleware`，
  并对显式路由（可反射 handler）补登记 `#[Feature]`；与限流的「属性 + 显式」双路径对称。

用法：

```php
// 路由级 gating（控制器方法）
#[Feature('new-checkout')]
public function checkout() {}

// 业务内即时判定（不依赖路由属性）
if (!feature('beta-search')) {
    return Resp::error('Not Found', 404);
}
```

验证：`tests/FeatureManagerTest.php`（enabled/rollout/默认/resolver/状态 + 属性读取器）、
`tests/FeatureMiddlewareTest.php`（命中放行、关闭 404/403、全局关闭放行、按用户稳定分桶）。
全量 **219 tests / 25563 assertions OK**（1 skipped）。

> 设计立场：开关判定逻辑全部内聚在 `FeatureManager`，中间件只做「匹配路由 → 查表 → 调判定 →
> 放行/拒绝」的薄编排；灰度/分桶的存储后端（Redis/DB/配置中心）通过 `registerResolver()` 注入，
> 框架不内置存储策略，保持可插拔、不越界（与多租户原语同一哲学）。

## 15. 配置中心薄壳层：可插拔配置源 + 运行时热重载（v0.8.10）

生产级部署需要「不重启进程即可改配置」的能力（接入 Nacos / Apollo / etcd 等远程配置中心，
或本地覆盖层做灰度/紧急降级）。框架**不内置任何远程中心客户端**（那是基础设施决策），只提供：

- **可插拔配置源抽象** `ConfigSource`：任意后端把「一份配置」暴露成数组即可接入；
- **运行时热重载** `ConfigCenter::reload()`：重新拉取可重载源并合并进 `Config`，派发 `ConfigReloaded` 事件；
- **优先级**：配置中心 sources 覆盖 `config/*.php` 文件值（高 → 低）。

新增组件：

- `config/center.php`：`enabled` 总开关、`sources` 列表（每项 `['class' => X, 'config' => [...]]`）。
- `src/Config/ConfigSource.php`：契约 `name() / load(): array / isReloadable(): bool`。
- `src/Config/FileConfigSource.php`：内置文件后端（PHP/JSON）。既是立即可用的本地覆盖层，
  也是远程中心的「本地镜像」范本——应用侧 watch 中心变更 → 写此文件 → 调 reload 生效。
- `src/Config/ConfigCenter.php`：管理器。`seed()` 启动期合并 sources；`reload()` 运行期合并可重载源、
  对比重载前后顶层键返回变化列表、派发 `ConfigReloaded` 事件（`?Closure` 注入解耦事件系统启动顺序）。
- `src/Config/ConfigReloaded.php`：事件对象（变化键 + 时间戳）。
- `src/Console/Commands/ConfigReloadCommand.php`：`bin/kode console config:center:reload` 触发热重载并打印变化键。
- `config_center()` 助手：取管理器（`null` 安全，未启用返回 null）。
- `ConfigCenterServiceProvider`：已接入 `Application::$defaults`，且**置于 `ConfigServiceProvider` 之前 boot**，
  使中心覆盖值在「必填校验 / 其他读配置 Provider」之前生效。

接入远程中心（零框架改动）：

```php
// App\Config\NacosConfigSource implements \Kode\Framework\Config\ConfigSource
// 构造接收 ['server'=>...,'dataId'=>...]，load() 拉取并解析成数组返回即可。
// 然后在 config/center.php 的 sources 加一项：
['class' => App\Config\NacosConfigSource::class, 'config' => ['server' => env('NACOS_ADDR'), 'dataId' => 'my-app']]
```

运行期再配置（监听事件做不重启调整）：

```php
event()->listen(ConfigReloaded::class, function (ConfigReloaded $e) {
    // 例如：调整日志级别、重建限流阈值、通知连接池重连新地址
});
```

用法：

```php
config_center()?->reload();                 // 返回变化的顶层键，如 ['log','app']
$keys = config_center()?->lastChangedKeys();
```

验证：`tests/ConfigCenterTest.php`（FileConfigSource 加载/缺省/异常类型、seed 合并与覆盖、
reload 变化键 + 事件派发、非可重载源跳过、诊断 API）、`tests/ConfigCenterIntegrationTest.php`
（`#[RunInSeparateProcess]` 真实引导：Provider 接线 + 中心值覆盖文件值 + 运行期 reload 生效）、
`tests/ConfigCenterDisabledTest.php`（未启用时 `config_center()` 返回 null，零副作用）。
全量 **230 tests / 25591 assertions OK**（1 skipped）。

> 设计立场：配置中心薄壳层**只定义抽象与热重载机制**，具体中心客户端交给应用/基础设施。
> 多进程（kode/process master-worker）下每个 worker 各持一份 `Config`，reload 作用范围由调用方决定
> （CLI 命令只影响当前进程；远程中心 watch 需在每 worker 触发，或由进程信号统一通知——后者交给
> 运维编排，框架不越界）。这与多租户原语、Feature Flags 一脉相承：框架给「契约 + 钩子」，不给「绑定实现」。

## 16. 服务发现薄壳层：可插拔注册表 + 负载均衡 + 健康检查（v0.8.11）

微服务/多上游架构需要「不写死地址」的能力（接入 Consul / Nacos / ZooKeeper / Etcd 等，
或本地静态声明做开发/灰度）。框架**不内置任何分布式发现客户端**（那是基础设施决策），只提供：

- **可插拔注册表抽象** `ServiceRegistry`：任意后端把「服务 → 实例」暴露出来即可接入；
- **内置静态注册表** `StaticServiceRegistry`：把 `config/services.php` 的声明加载为本地注册表，立即可用；
- **运行时解析** `ServiceDiscovery::resolve()`：从健康实例中按策略（`round_robin` / `random` / `first`）做客户端负载均衡；
- **健康检查** `heartbeat($id, $healthy)`：上报实例健康，状态由健康→不健康时派发 `ServiceUnhealthy` 事件；
- **事件**：`ServiceDiscovered`（新实例注册）、`ServiceUnhealthy`（健康→不健康）。

新增组件：

- `config/services.php`：`enabled` 总开关、`default_strategy` 默认负载均衡策略、`services` 静态声明。
- `src/ServiceDiscovery/ServiceInstance.php`：实例运行期表示（身份字段不可变，健康字段由 heartbeat 更新）。
- `src/ServiceDiscovery/Contracts/ServiceRegistry.php`：契约 `register/unregister/get/discover/all/names/count`。
- `src/ServiceDiscovery/StaticServiceRegistry.php`：内置后端。`seed()` 从 config 批量播种；同时是真实发现后端的范本
  （应用侧 watch 远程中心变更 → 调 register/unregister 同步，框架其余逻辑零改动）。
- `src/ServiceDiscovery/ServiceDiscovery.php`：管理器。`resolve()` 负载均衡、`heartbeat()` 健康检查 + 事件、
  `stats()` 健康统计（供探针/诊断），`?Closure` 注入解耦事件系统启动顺序。
- `src/ServiceDiscovery/ServiceDiscovered.php` / `ServiceUnhealthy.php`：事件对象。
- `src/Console/Commands/ServiceListCommand.php`：`bin/kode console service:list` 列出已注册服务及其实例/健康/权重。
- `service()` / `service_url()` 助手：`service('pay')` 取健康实例、`service_url('pay')` 取完整 URL、`service()` 取管理器（均 null 安全）。
- `ServiceDiscoveryServiceProvider`：已接入 `Application::$defaults`（位于 `FeatureServiceProvider` 之后），boot 期按 config 播种。

接入真实分布式发现（零框架改动）：

```php
// App\Discovery\ConsulRegistry implements \Kode\Framework\ServiceDiscovery\Contracts\ServiceRegistry
// 构造接收 ['address'=>..., 'token'=>...]，discover() 调 Consul Catalog API 返回 ServiceInstance[]。
// 然后在 ServiceDiscoveryServiceProvider 的 boot() 中把内置 StaticServiceRegistry
// 换成 $this->container->instance(ServiceRegistry::class, new ConsulRegistry($cfg));
// 即可。service()/service_url() 助手与心跳事件机制无需任何改动。
```

用法：

```php
// 解析支付服务的健康实例地址（默认 round_robin）
$url = service_url('payment');                 // → http://10.0.0.1:8080

// 取实例对象做更细操作
$inst = service('payment');                    // → ?ServiceInstance
$inst?->url();

// 运行期上报健康（通常由健康检查协程/探针调用）
service()->heartbeat($inst->id, false);        // 健康→不健康 → 派发 ServiceUnhealthy

// 监听发现事件
event()->listen(ServiceUnhealthy::class, function (ServiceUnhealthy $e) {
    // 摘除实例 / 告警 / 触发重新发现
});
```

验证：`tests/ServiceDiscoveryTest.php`（实例/注册表/负载均衡策略/健康检查事件/统计/事件派发）、
`tests/ServiceDiscoveryIntegrationTest.php`（`#[RunInSeparateProcess]` 真实引导：Provider 接线 + config 播种 +
`service()`/`service_url()` 助手 + 运行期注册与解析）。全量 **244 tests / 25641 assertions OK**（1 skipped）。

> 设计立场：服务发现薄壳层**只定义抽象、负载均衡与健康机制**，具体发现后端交给应用/基础设施。
> 多进程（kode/process master-worker）下每个 worker 各自持有注册表；跨进程同步的发现/健康检查由真实后端或
> 运维编排负责，框架不越界。这与配置中心、Feature Flags、多租户原语一脉相承：框架给「契约 + 钩子」，不给「绑定实现」。



---

## 17. 分布式追踪 / OTLP 导出（Tracer）

框架此前已内置 **W3C traceparent 传播**（`TraceContext` + `TraceMiddleware`）+ **Metrics** 子系统，但缺「span 录制 + 导出」——链路只生成 ID 不落地。本節补齐 OTLP 分布式追踪薄壳层。

### 组件与边界

- `Observability/Trace/Span`：span 运行期表示（身份只读，end/status/events 由 Tracer 回填）。
- `Observability/Trace/Tracer`：核心管理器。
  - `start()` 基于当前链路（kode/context 的 `trace_id/span_id`）开子跨度；`end()` 回填时长/状态并入缓冲；
    `flush()` 经导出器落盘。
  - **active 栈与待导出缓冲存于 `kode/context`**（按执行单元隔离），并发 fiber / 进程 / 线程各持独立链路，
    天然支持 kode/fibers 的 active runtime。
  - **采样**：根 span 按 `sample_ratio` 决策，子 span 继承父采样，保证一条链路一致。
  - **导出时机**：根 span 结束且 `flush_on_request_end=true` 时自动 flush；CLI / 常驻 worker 用
    `bin/kode console tracing:flush` 或 `tracer()->flush()` 手动落盘；worker 优雅停机时由
    `GracefulShutdown` 注册清理回调自动 flush。
- `Observability/Trace/Exporters/*`：**内置 OTLP/HTTP(JSON) 与文件(NDJSON) 两种导出器**；
  真实后端（OTLP/gRPC、protobuf、第三方 APM）只需实现 `Contracts/SpanExporter` 注入容器，Tracer 零改动复用。
- `Observability/Trace/SpansFlushed`：导出完成事件（成功/失败均派发），可接指标/告警。
- `tracer()` 助手（null 安全）、`tracing:flush` 命令、`config/observability.php` 的 `tracing.*` 配置段。

### TraceMiddleware 增强

`TraceMiddleware`（全局最外层）在请求进入时若 tracing 启用，开启一个 **SERVER 根 span** 覆盖整条请求
（含下游中间件与处理）；其 `span_id` 与响应 `traceparent` 一致，子调用经 `tracer()->start()` 自然嵌套；
响应返回时回填状态（5xx → ERROR）并 flush。

### 用法

```php
// HTTP 入口自动产生 SERVER 根 span（无需手写）
// 业务内手动开子跨度：
$span = tracer()->start('订单创建', ['order.id' => 123], \Kode\Framework\Observability\Trace\SpanKind::INTERNAL);
try {
    // ... 业务 ...
} catch (\Throwable $e) {
    tracer()->recordException($span, $e);
    tracer()->end($span, \Kode\Framework\Observability\Trace\SpanStatus::ERROR, $e->getMessage());
    throw $e;
}
tracer()->end($span);

// 跨服务串联：把当前链路头注入下游 HTTP 调用
$headers = trace()::outgoingHeaders();   // 已由 TraceContext 提供
```

配置（`config/observability.php`）：

```php
'tracing' => [
    'enabled'      => true,
    'service_name' => env('APP_NAME', 'kode-app'),
    'sample_ratio' => (float) env('OBS_TRACING_SAMPLE_RATIO', 1.0),
    'exporter'     => env('OBS_TRACING_EXPORTER', 'otlp_http'),   // otlp_http | file | 自定义类名
    'otlp'        => ['endpoint' => 'http://localhost:4318/v1/traces', 'timeout' => 2],
    'file'        => ['path' => sys_get_temp_dir().'/kode-traces.ndjson'],
],
```

验证：`tests/TracingTest.php`（no-op/采样/active 栈嵌套/缓冲/flush/异常事件/SpansFlushed/文件导出/OTLP 映射）、
`tests/TracingIntegrationTest.php`（`#[RunInSeparateProcess]` 真实引导：HTTP 请求穿过 TraceMiddleware 自动产生 SERVER span 并 flush）、
`tests/TracingDisabledTest.php`（关闭态助手返回 null、禁用 Tracer 不产生 span）。全量 **260 tests / 25699 assertions OK**（1 skipped）。

> 设计立场：框架只做「span 录制 + 标准 OTLP 导出契约」，不内置 OpenTelemetry SDK；真实 exporter
> （gRPC / protobuf / 第三方 APM）实现 `SpanExporter` 注入即零改动接入。采样、导出时机、端点交给配置。
> 这与配置中心、服务发现、Feature Flags 一脉相承：框架给「契约 + 钩子」，不给「绑定实现」。


---

## 18. 多租户存储隔离脚手架（Tenant Storage Isolation）

框架原本已有「租户上下文原语」（`TenantContext` + `TenantResolver` + `TenantMiddleware`，按请求解析租户），
但明确「不做任何存储隔离」。本節补齐**按租户切换 DB 连接**的薄壳层——生产级 SaaS 的关键一环。

### 组件与边界

- `Tenant/Storage/TenantConnectionResolver`：**契约**。`resolve(tenantId): ?array` 返回 kode/database 连接配置；
  返回 null 表示「不隔离 / 仍用默认连接」。真实后端（中心租户表、配置中心）实现本接口注入即零改动复用。
- `Tenant/Storage/StaticTenantStorageResolver`：**内置静态后端**，支持四种策略：
  - `shared`：不隔离（默认），租户仅作上下文标签；
  - `database`：每租户独立库，`database = prefix + sanitize(租户标识)`（基于 `template` 连接克隆派生）；
  - `schema`：语义同 database（thin-shell 落到 database 命名，应用可叠加 schema/search_path 策略）；
  - `map`：`tenant id => 已注册连接名(string)` 或 `连接配置覆盖(array)` 的显式映射。
  - 自定义 `<FQCN>` 实现 `TenantConnectionResolver` 也可直接作为 `strategy` 注入（动态从中心库查凭证）。
- `Tenant/Storage/TenantStorageManager`：**核心**。
  - `boot(tenantId)`：解析→懒注册 `Db::addConnection`→`Db::setDefaultConnection(租户连接)`，
    返回「切换前的默认连接名」，并派发 `TenantStorageSwitched` 事件；
  - `restore(切换前连接名)`：在中间件 `finally` 中把默认连接还原，**绝不跨请求串扰**；
  - `currentConnection()`：当前请求级激活的租户连接名（null = 未隔离 / 已恢复）。
- `Tenant/Storage/TenantStorageMiddleware`（PSR-15，**运行于 TenantMiddleware 内层**）：
  读取 `TenantContext::id()`，非零时切换连接、响应后恢复；`on_missing=abort` 且租户无映射时
  抛 `TenantStorageUnresolved` → 转为标准 **404**（`KodeException::notFound`，由 ExceptionMiddleware 渲染）。
- `tenant:storage:list` 命令：dry-run 诊断 storage 策略与租户连接映射（不真正连库、不切换）。
- `tenant_storage()` / `tenant_connection()` 助手（null 安全）。

### 并发模型（重要）

`kode/process` 下单 worker 一次处理一个请求，`boot/restore` 在中间件 `try/finally` 中成对出现，
连接切换严格限定在单个请求 scope 内。对需要**逐查询**严格隔离的 `kode/fibers` active runtime，
业务侧可用 `tenant_storage()->connectionName($id)` 取连接名后显式 `Db::connection($name)->table(...)`，
本管理器同样暴露连接名供此用途。

### 用法

```php
// config/tenant.php
'tenant' => [
    'enabled' => true,
    'resolver' => 'header',          // 从 X-Tenant-Id 解析租户
    'storage' => [
        'enabled' => true,
        'strategy' => env('TENANT_STORAGE_STRATEGY', 'database'), // shared|database|schema|map|<FQCN>
        'template' => 'mysql',
        'prefix' => 'tnt_',
        'on_missing' => 'fallback',   // fallback(用默认) | abort(404 拒绝)
    ],
],

// 业务内（无需手写切库，中间件已按请求自动切换）：
$rows = Db::table('orders')->where('tenant_id', tenant())->get(); // 自动落在 tenant_acme 库
```

验证：`tests/TenantStorageTest.php`（shared/database/schema/map 策略、sanitize、boot/restore、
currentConnection、on_missing=abort 转 404、中间件零开销放行）、`tests/TenantStorageIntegrationTest.php`
（`#[RunInSeparateProcess]` 真实引导：带 X-Tenant-Id 的请求切换并恢复连接、不带则放行）、
`tests/TenantStorageDisabledTest.php`（关闭态助手返回 null、shared 不切换）。全量 **275 tests / 25737 assertions OK**（1 skipped）。

> 设计立场：框架只做「解析 → 切换 → 恢复 → 事件」与「内置静态策略」，不内置任何租户元数据存储；
> 真实后端实现 `TenantConnectionResolver` 注入即零改动复用——与配置中心、服务发现、Feature Flags、OTLP 追踪
> 同一哲学：框架给「契约 + 钩子」，不给「绑定实现」。

---

## 19. 健康检查：就绪探针 + 能力感知（v0.8.14）

生产级部署需要「编排系统（k8s / LB）能判断进程存活与依赖就绪」的能力，且就绪判定要覆盖
框架已接线的**全部企业级依赖**（v0.8.10–0.8.13 的配置中心 / 服务发现 / 追踪 / 租户存储），
而不只是裸 db/cache/queue。本節把健康子系统从「仅 db/cache/queue」增强为**能力感知**的薄壳层。

### 四类探测合一

`HealthChecker`（`src/Health/HealthChecker.php`）聚合三类探针 + liveness：

1. **配置驱动探针**（`config/health.php` 的 `checks`）：`db` / `cache` / `queue` 布尔开关 + 任意自定义闭包
   （签名 `fn(ContainerInterface $c) => 'ok' | 'error: ...'`）；
2. **能力感知探针（自动）**：对已接线的企业级子系统做**只读**可达性检查——
   `config_center` / `service_discovery` / `tracing` / `tenant_storage`；子系统未启用（助手返回 null）
   即 `not_configured`，**不计入失败**；
3. **app 自身**：永远 `ok`；
4. **/health/live**：liveness（仅存活判定，不含任何外部依赖探测，永远 200）。

探针返回值语义：`ok` / `error: <原因>` / `not_configured`。任一 `error` 即整体 `degraded`
（/health/ready 返回 **503**，使流量在依赖未就绪时被摘除）；`not_configured` 不影响健康。

### 端点（始终存在，不依赖用户路由）

由 `HttpServiceProvider::registerHealthEndpoints()` 注册，便于编排系统直接探活：

| 端点 | 语义 | 状态码 |
| --- | --- | --- |
| `/health/live` | liveness（重启判定，k8s `livenessProbe`） | 永远 200 |
| `/health/ready` | readiness（依赖就绪，k8s `readinessProbe`） | 健康 200 / 降级 503 |
| `/health` | 聚合视图：version / PHP / env / time + components 明细 | 200 |
| `/ping` | 极简 `pong` | 200 |

> 修复：`/health` 聚合端点此前用 `new HealthChecker($config, null)` 构造（容器为 null），导致
> `cache` / `queue` 探针永远 `not_configured`（无法解析连接器）。现统一走 `HealthServiceProvider`
> 绑定的**单例**（已注入容器 + 事件派发闭包），四个端点共用同一份探测结果。

### 命令（k8s exec 探针 / CI 门禁）

```bash
bin/kode console health:check            # 聚合巡检，打印各组件状态，degraded 以非零码退出
bin/kode console health:check --ready    # 仅就绪语义（与 /health/ready 一致）
bin/kode console health:check --json     # JSON 输出（便于监控 / 编排系统解析）
```

退出码：健康 = `0`，degraded（任一 `error`）= `1`，直接对接 k8s exec / CI 失败感知。

### 事件（依赖未就绪即告警）

每次探测（HTTP 就绪探针 / CLI 命令）后派发 `HealthChecked`（`src/Health/HealthChecked.php`），
携带 `healthy` / `checks` / `mode`。监听即可做指标采集 / 告警 / 日志：

```php
event()->listen(\Kode\Framework\Health\HealthChecked::class, function ($e) {
    if (!$e->healthy) {
        // 上报「依赖未就绪」指标 / 告警
    }
});
```

事件派发经 `HealthServiceProvider` 注入的闭包解耦（`event()` 在运行时才调用），不依赖事件系统
启动顺序——与配置中心、服务发现的 `?Closure` 注入范式一致。

### 助手（业务内即时探测）

```php
$r = health()?->check();                 // ['healthy' => bool, 'checks' => [...]]，未引导返回 null
$r = health()?->check('ready');          // 就绪语义
```

### 并发与隔离

`HealthChecker` 为无状态聚合器（每次 `check()` 新建结果数组，不写任何全局/静态），多进程
（kode/process master-worker）下每请求/每命令各自独立探测，天然无跨请求串扰。能力感知探针
**只读**（不 mutate 配置 / 不 flush 追踪 / 不真正切库），对就绪检查零副作用。

### 用法与覆盖

```php
// config/health.php —— 关闭某个已接线能力对就绪判定的计入（如追踪降级不应摘流）：
'checks' => [
    'db'    => env('HEALTH_CHECK_DB', true),
    'cache' => env('HEALTH_CHECK_CACHE', false),
    'queue' => env('HEALTH_CHECK_QUEUE', false),
    'tracing' => false,          // 同名键 false 可关闭自动能力探针
],

// 自定义探针（如 Redis 连通性）：
'checks' => [
    'redis' => function ($c) {
        $r = $c->get(\Kode\Cache\CacheManager::class)->connection('redis');
        return $r->ping() ? 'ok' : 'error: no pong';
    },
],
```

验证：`tests/HealthCheckerTest.php`（默认健康 / 自定义 ok·error·异常降级 / 未知内置名 not_configured /
能力探针未接线 not_configured / 同名 false 可关闭 / 事件派发 / 空 dispatcher 安全）、
`tests/HealthEndpointTest.php`（`#[RunInSeparateProcess]` 真实引导：/health/live 200、/health/ready 200+结构、
/health 含 version、tenant_storage 能力探针随启用出现）。全量 **288 tests / 25775 assertions OK**（1 skipped=MySQL）。

> 设计立场：健康子系统是「已接线能力的只读可达性聚合器」，不重新实现任何依赖的连通性协议；
> 能力感知探针随对应子系统自动纳入，未启用即 `not_configured`——与配置中心、服务发现、OTLP 追踪、
> 多租户存储同一哲学：框架给「契约 + 钩子 + 聚合」，不给「绑定实现」。

## 20. 分布式锁薄壳层（Distributed Lock）（v0.8.15）

防止多副本 / 多 worker / 多进程下的重复执行与竞态——典型场景：同机多副本 cron、队列消费幂等、
缓存击穿重建互斥、定时报表生成。框架只给「契约 + 内置静态后端 + 事件 + 命令 + 助手」，
跨主机共享锁由实现契约的后端提供（不重造分布式协调算法）。

### 抽象与边界

- `Lock\LockManager`（契约）：`acquire / release / isLocked / owner / ttl / forceRelease / keys / run`；
- `Lock\StaticLockManager`（内置后端，零依赖）：
  - `driver=memory`：进程内静态表，覆盖单实例与同进程内并发（Fiber / 协程 / 同请求多次调用）；
  - `driver=file`：文件落盘（默认 `storage_path('framework/locks')`），覆盖同主机多进程互斥；
  - owner 令牌：每个管理器实例持有唯一令牌，释放 / 强制释放仅当 owner 匹配（或强制）时生效，
    避免多副本误释放他人持有的锁；
  - 惰性过期：每次访问校验 TTL，到期即视为未持有（无需后台 reaper）；
- 跨主机分布式锁（Redis / etcd / DB 乐观锁）不在此实现：在应用层实现 `LockManager` 并经
  `config/app.php` 的 `providers` 绑定即可零改动替换，`lock()` 助手与 `lock:list` 命令 API 完全一致。

### 接线与用法

`LockServiceProvider` 无条件绑定 `LockManager` 单例（注入事件派发闭包），已接入 `Application::$defaults`。

```php
use function Kode\Framework\lock;

// 获取 → 执行 → 释放（获取失败抛 LockAcquireException，finally 保证释放）
$ok = lock()?->run('report:daily', function () {
    return buildDailyReport();
}, 120);

// 手动获取 / 释放（仅 owner 可释放）
if (lock()?->acquire('cache:rebuild', 30)) {
    try { rebuild(); } finally { lock()?->release('cache:rebuild'); }
}

// 运维兜底 / 死锁清理（忽略 owner）
lock()?->forceRelease('report:daily');
```

- HTTP / CLI 之外：`bin/kode console lock:list`（表格 / `--json`）列出当前持有的锁（键 / owner / 剩余 TTL）；
  memory 后端反映当前进程，file 后端反映同主机多进程。
- 事件：`Lock\LockAcquired` / `Lock\LockReleased`（含 `$forced`）在每次成功获取 / 释放后派发，
  便于接入指标（锁竞争 / 持有时长）/ 审计。

### 关键约定

- **TTL 必须保守且业务幂等**：锁只防并发，不防进程崩溃；持有者崩溃且 TTL 内未释放，锁会「假性持有」
  直到过期——故被保护操作必须幂等，过期后由下一个持有者重入重试。
- **owner 不匹配拒绝释放**：`release($key, $owner)` 仅当 owner 为 null（用管理器实例令牌）或显式匹配时成功；
  跨进程用不同 owner 令牌可正确互斥。
- **不要依赖 memory 后端做跨进程互斥**：跨进程 / 跨机请实现 `LockManager` 接 Redis 等共享存储。

验证：`tests/LockTest.php`（acquire / 异 owner 互斥 / 同 owner 重入刷新 TTL / owner-only 释放 / 惰性过期 /
forceRelease 标记 forced / keys 过期过滤 / run 执行释放 / 占用抛异常 / 异常仍释放 / file 后端落盘）、
`tests/LockIntegrationTest.php`（`#[RunInSeparateProcess]` 真实引导：lock() 解析单例、run 经真实容器、
LockAcquired / LockReleased 经框架事件系统派发）。全量 **303 tests / 25821 assertions OK**（1 skipped=MySQL）。

> 设计立场：分布式锁是「协作互斥原语」，框架只做契约 + 内置零依赖后端（进程内 / 同主机文件），
> 把「跨主机一致性」交给专业后端——与配置中心、服务发现、OTLP 追踪、多租户存储、健康巡检同一哲学：
> 框架给「契约 + 钩子 + 默认值」，不给「绑定实现」。

## 21. 幂等薄壳层（Idempotency）（v0.8.16）

保证「重试安全」——同一请求 / 消息在 TTL 内只成功处理一次，重放返回一致语义（典型：Stripe 风格
幂等键、消息队列至少一次投递去重、支付 / 下单防重复提交）。与分布式锁（v0.8.15）互补：
**锁 = 并发互斥（同一时刻仅一个持有者运行）；幂等 = 重试安全（同一 key 只处理一次）**，两者解决
不同问题，常配合使用。

### 抽象与边界

- `Idempotency\IdempotencyStore`（持久化契约）：`has / put / forget / ttl / keys / prune`（仅记录
  「已处理」事实 + 到期时间，不做响应体缓存——那是上层 HTTP 幂等中间件的职责）；
- `Idempotency\IdempotencyManager`（语义契约）：`once / seen / forget / store`；
- `Idempotency\StaticIdempotencyStore` + `Idempotency\StaticIdempotencyManager`（内置零依赖后端）：
  - `driver=memory`：进程内静态表（单实例 / 同进程 Fiber·协程并发）；
  - `driver=file`：文件落盘（`storage_path('framework/idempotency')`），覆盖同主机多进程去重；
  - 惰性过期（无需后台 reaper）；`LOCK_EX` 原子落盘；
- 跨主机共享去重（Redis / etcd / DB）不在此实现：实现 `IdempotencyStore` 经 `providers` 绑定即零改动替换。

### 接线与用法

`IdempotencyServiceProvider` 无条件绑定 `IdempotencyManager` 单例（注入事件派发闭包），已接入 `Application::$defaults`。

```php
use function Kode\Framework\idempotency;

// 首次执行并返回结果；TTL 内重放抛 DuplicateRequest（上层据此返回 409 / 缓存响应）
$res = idempotency()?->once($requestId, fn () => charge(), 3600);

// 仅判重（首次 true，重复 false），自行处理响应
if (idempotency()?->seen($key, 3600)) { /* 首次 */ }

// 业务失败 → once() 自动回滚记录，允许修复后重试（避免永久死锁在重复态）
idempotency()?->forget($key);   // 主动重试放行 / 运维清理
```

- 运维命令：`idempotency:list`（表格 / `--json`，列出键 + 剩余 TTL）、`idempotency:forget <key>`（删除指定键）；
- 事件：`IdempotencyRecorded`（首次记录）/ `IdempotencyHit`（重复命中）便于指标（重复请求率）/ 审计。

### 关键约定

- **once() 的事务语义**：业务正常 → 记录保留（重放判重）；业务抛异常 → 回滚记录，调用方可重试。
- **TTL 选择**：应覆盖「客户端最大重试窗口 + 处理耗时」，过短会漏掉迟到重放，过长会拖延合法重试放行。
- **不要依赖 memory 后端做跨进程去重**：跨进程 / 跨机请用实现 `IdempotencyStore` 的共享存储后端。
- **与锁配合**：高并发「首次计算」可用锁保证单飞，幂等层负责「重放一致」，两者职责不重叠。

## 22. HTTP 幂等中间件（Idempotency Middleware）（v0.8.17）

把幂等薄壳层（§21）接入 HTTP 流量，让「同一幂等键的重复请求」在 TTL 内只真正执行业务一次，
重放返回**首次的缓存响应**（状态 / Content-Type / 响应体完全一致），而非重复执行或仅回 409。
即开即用的生产级防重复提交（Stripe 风格 `Idempotency-Key`）。

### 抽象与边界

- `Idempotency\IdempotencyMiddleware`（PSR-15，运行于全局中间件栈、限流之后、业务之前）：
  - 默认仅对携带 `Idempotency-Key` 头的请求生效；**缺头默认放行、零开销**（不强制）；
  - 原子占位 `seen()`：首次跑下游 → 把响应编码为 envelope 补挂 `attach()`，响应带 `Idempotency-Recorded: true`；
  - 重放 → 取 `replay()` 缓存 envelope 原样重建响应，带 `Idempotency-Replay: true`；
  - 极窄并发窗口（占位成功但响应尚未落盘）→ 降级 409，避免重复执行业务；
  - `enforce=true` 时缺头回 400（写接口强制要求幂等键）。

### 接线与配置（config/idempotency.php 的 `http` 段）

`HttpServiceProvider` 在 `idempotency.http.enabled`（默认 `true`）时注册中间件（注入 `IdempotencyManager`
单例 + `http` 配置）。关键选项：

| 选项 | 默认 | 说明 |
| --- | --- | --- |
| `header` | `Idempotency-Key` | 客户端携带的幂等键请求头 |
| `enforce` | `false` | 缺头是否强制 400（写接口建议开） |
| `ttl` | `3600` | 重放缓存 TTL（秒），到期自动失效 |
| `scope` | `global` | `global`=键即身份（跨端点唯一）；`route`=叠加 `METHOD path` 防跨端点碰撞 |
| `prefix` | `''` | 键前缀（多应用共用存储时命名空间隔离） |
| `replay_header` / `recorded_header` | `Idempotency-Replay` / `Idempotency-Recorded` | 响应标记头 |

```php
// 客户端：首次提交携带幂等键，重试（网络抖动 / 网关重试）用同一键 → 拿到首次结果，业务不重复跑
$curl->setHeader('Idempotency-Key', $uuid);
$resp = $client->post('/api/charge', $payload);
// 首跑：Idempotency-Recorded: true；重试（同键）：Idempotency-Replay: true + 与首跑完全一致的响应体
```

### 关键约定

- **响应缓存载体**：envelope 由中间件编码（状态 / Content-Type / base64 体）并落在 `IdempotencyStore`，
  故 `driver=file` 时缓存也落盘（同主机多进程重放一致）；跨机重放需实现 `IdempotencyStore` 的共享后端。
- **scope 选择**：开放 API 用 `global`（键即身份，安全）；同键可能复用在不同端点的内部系统用 `route` 防误判。
- **GET 也幂等**：中间件不区分方法，任何携带键的请求都走首次/重放逻辑；纯查询接口无需带键（缺头放行）。
- **与 §21 同一哲学**：框架只做「中间件 + 内置存储」，跨主机共享去重由实现 `IdempotencyStore` 的后端零改动替换。


验证：`tests/IdempotencyTest.php`（once 首次执行 / 重放抛 DuplicateRequest / 失败回滚允许重试 / seen 语义 /
forget 重试放行 / 惰性过期 / store keys 过滤 / file 后端落盘）、`tests/IdempotencyIntegrationTest.php`
（`#[RunInSeparateProcess]` 真实引导：idempotency() 解析单例、once 重放抛异常、IdempotencyRecorded /
IdempotencyHit 经框架事件系统派发）。全量 **315 tests / 25846 assertions OK**（1 skipped=MySQL）。

> 设计立场：幂等是「重试安全原语」，框架只做契约 + 内置零依赖存储（进程内 / 同主机文件），
> 把「跨主机去重」交给专业后端——与配置中心、服务发现、OTLP 追踪、多租户存储、健康、分布式锁同一哲学：
> 框架给「契约 + 钩子 + 默认值」，不给「绑定实现」。





