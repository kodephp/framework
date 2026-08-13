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
