# Kode Framework 源码审计报告

- **审计对象**：`/Users/Zhuanz/Desktop/website/composer/framework/src`（254 个 PHP 文件，约 21,437 行）+ 根目录 `config/*.php` 生效配置
- **审计方法**：逐文件静态审读，交叉验证配置、装配顺序与调用链；无 PHP 运行时，结论均基于源码证据，未作动态验证
- **审计日期**：2026-08-22
- **框架版本**：Application::VERSION = `0.8.41`

## 一、结论总览

| 级别 | 数量 | 一句话概括 |
|---|---|---|
| 🟥 严重（Critical） | 2 | 锁看门狗异常路径**重复执行业务**；pcntl 超时调度器**从不启动闹钟**，超时形同虚设 |
| 🟧 高（High） | 8 | 幂等双执行/丢响应头、审计丢用户、限流与灰度信任可伪造头、熔断键未归一化、日志泄密、限流配置错读 |
| 🟨 中（Medium） | 2 | 进程级静态审计/访问日志队列在 fiber 并发下有竞态窗口；`config/app.php` 被多次 `require` |
| 🟩 低 / 观察项 | 5 | 采样冗余、多 worker 等待空转、vendor 依赖待验证、异常静默等 |

**最优先修复**：C1（业务双执行）与 C2（假超时）是会造成线上事故的确定性缺陷，其余按分区修复。

---

## 二、严重（Critical）

### C1. LockWatchdog 异常路径导致同一业务被再次执行

- **位置**：`src/Lock/LockWatchdog.php:116-123`（`defaultTicker`）、`154-174`（`fiberTicker`）
- **问题描述**：`driver=auto` 时先尝试 `fiberTicker($work, ...)`，其 `catch (\Throwable)`（119 行）把**任何异常**都当作「fiber 不可用」，随后回退执行 `tickTicker($work, ...)`——**同一份 `$work` 被再次完整执行**。而 `fiberTicker` 中 `$work()`（170 行）抛出的业务异常同样会被捕获并触发重执行。分布式锁场景下意味着：持锁任务内任何一次抛错，都会产生**部分执行的副作用 + 第二次完整执行**，比「锁失效不续期」后果更严重（重复扣款、重复发消息、重复出报表）。
- **根因分析**：回退意图是处理「无 fiber 调度器上下文」——该场景应由 `Fibers::go`（159 行）启动失败来识别，而不是用 `try/catch` 包住整个 `$work()` 执行。`catch` 把「调度器不可用」与「业务异常」两类性质完全不同的事件混为一谈。
- **修复建议**：
  1. 把 `Fibers::go` 单独用 try/catch 包裹（仅在创建 watchdog 协程失败时回退），`$work()` 的异常必须原样向上抛，绝不重试执行；
  2. 或在 `defaultTicker` 进入前用现有 `fiberAvailable()`（176 行）先探测调度器一次，再确定驱动，避免运行时回退；
  3. 增加 `LockWatchdog` 单元测试：work 抛 `RuntimeException` 时断言仅执行一次。

### C2. PcntlTimeoutScheduler 从不启动 alarm，超时永不触发

- **位置**：`src/Resilience/TimeoutScheduler/PcntlTimeoutScheduler.php:35-56`
- **问题描述**：第 35 行计算出 `$secondsInt = max(1, (int) ceil($seconds))` 后，**从未调用 `pcntl_alarm($secondsInt)` 设置定时器**；第 40-43 行注册的 SIGALRM handler 永不触发，`TimeoutExceeded` 永不抛出，真正执行的只有第 47/52 行的 `pcntl_alarm(0)`（取消防护）。`config/resilience.php` 注释声称「pcntl = CLI 下 pcntl_alarm 硬中断（opt-in）」，实际该选项完全失效——显式选用 `scheduler: 'pcntl'` 的用户会以为有硬中断超时保护，真实场景下业务可无限挂起。
- **根因分析**：漏写启动调用，仅实现了「进入前保存、退出后恢复/取消」的收尾逻辑，核心启动步骤缺失；属于复制框架时的半成品实现。
- **修复建议**：
  ```php
  try {
      pcntl_alarm($secondsInt);   // ← 缺失的启动
      $result = $op();
      pcntl_alarm(0);
      pcntl_signal(SIGALRM, $previous);
      return $result;
  } catch (\Throwable $e) {
      pcntl_alarm(0);
      pcntl_signal(SIGALRM, $previous);
      throw $e;
  }
  ```
  另补充测试：`run(fn() => sleep(10), 0.1)` 应抛出 `TimeoutExceeded`。

---

## 三、高（High）

### H1. AuditService 读取即清除 auth_user_id，同请求内后续审计丢失用户 ID

- **位置**：`src/Security/Audit/AuditService.php:229-245`（`resolveUser()`，清除在第 236-238 行）；调用点 `src/Security/Audit/AuditMiddleware.php:35,39`；写入点 `src/Http/Middleware/AuthMiddleware.php`（鉴权成功分支写 `auth_user_id`）
- **问题描述**：`resolveUser()` 在读取 `auth_user_id` 后立即 `Context::set('auth_user_id', null)`。请求处理时序为：`AuthMiddleware` 写 `auth_user_id` → handler 内业务调用 `audit()->event(...)`（`recordEvent` 第 125 行经 `resolveUser` 消费并**清空**）→ 响应返回时 `AuditMiddleware::record()`（39 行，不传 userId）再走 `resolveUser()` 已是 `null`。**业务代码一旦在请求中调用过审计事件，请求级审计记录的 `user_id` 必然丢失**。
- **根因分析**：「读取后立即清除防跨请求泄漏」的时机错误。清除应在**请求边界**（AuditMiddleware::record 之后）统一做一次，而非每次读取即消费——单请求内的多次审计调用共享同一个身份。
- **修复建议**：将清除动作从 `resolveUser()` 中移除，改为在 `AuditMiddleware::record()` 收尾处按请求 scope 清理（`kode/context` 的 `runWith` scope 退出时自动回收则无需手动清除）；保持 `capture_user` 配置语义不变。

### H2. IdempotencyMiddleware 幂等重放丢失 Set-Cookie / Location 等响应头

- **位置**：`src/Idempotency/IdempotencyMiddleware.php:113-141`（`envelope()` / `rebuild()`）
- **问题描述**：envelope 只持久化 `{s: status, c: Content-Type, b: body-base64}`，rebuild 只还原三者。注释声称「保持状态 / Content-Type / 体一致」，实际**所有其他响应头（Set-Cookie、Location、ETag、Cache-Control 等）在重放时全部丢失**。对登录/下单类接口：首次请求下发的会话 Cookie 在重放响应中不存在，客户端将处于未登录状态，与首次响应行为不一致。
- **根因分析**：envelope 字段裁剪过度，仅覆盖「状态 + 单一头 + 体」，未考虑响应头的语义重要性。
- **修复建议**：将完整响应头列表序列化进 envelope（头名小写、逗号拼接同名字段），或至少白名单保留语义关键头（Set-Cookie / Location / Cache-Control）；重建时恢复。

### H3. StaticIdempotencyStore file 模式并发占位非原子，幂等去重失效

- **位置**：`src/Idempotency/StaticIdempotencyStore.php:41-49`（`put()`）、`155-168`（`write()`：写 `.tmp` + `rename()`）
- **问题描述**：`put()` 为 check-then-act：`has()`（36 行）与 `write()` 之间无原子互斥。两个并发请求在同一键上：均通过 `has()` 检测 → 均执行 `write()`（各自 `.tmp` + `rename`），**后 rename 覆盖先 rename**，两个 `put()` 都返回 `true`。`once()` / HTTP 幂等依赖「先占位者胜」，此语义在文件后端多进程/多 worker 下失效 → **相同幂等键并发请求重复执行业务**。
- **根因分析**：`rename()` 自身是原子的，但「检查-写入」两步缺整体原子性；也未使用 `flock` 对目标文件加锁。
- **修复建议**：file 模式下 `write()` 前对目标文件用 `flock($fh, LOCK_EX | LOCK_NB)` 做原子占位，拿不到锁即返回「已存在」；或改用原子创建语义（`fopen($file, 'x')` 独占创建），失败即视为已被占位。

### H4. 缺少受信代理机制：限流可被伪造 X-Forwarded-For 绕过、灰度分桶可被伪造头操纵

- **位置**：
  - `src/Http/Middleware/RateLimitMiddleware.php:192-201`（`clientIp()` 信任 `x-forwarded-for` 首个值）
  - `src/Security/Audit/AuditService.php:247-260`（`clientIp()` 信任 `X-Forwarded-For` / `X-Real-IP`）
  - `src/Feature/Middleware/FeatureMiddleware.php:75-90`（`bucketKey()` 直接取 `X-User-Id` → `X-Tenant-Id` → `REMOTE_ADDR`）
- **问题描述**：框架**没有可信代理（trusted proxies）配置**。三处来源语义不一致：
  1. 限流 key（`RateLimitMiddleware.php:79,116`）含 `clientIp()`，攻击者改 `X-Forwarded-For` 头即可**切换限流桶、绕过 IP 限流**；
  2. 审计 IP 溯源同样可被伪造（合规失真）；
  3. Feature 灰度优先信任客户端可自设的 `X-User-Id` / `X-Tenant-Id` 头，攻击者可**任意指定自己的灰度分桶**，看到本不应开放的功能（若灰度承载破坏性变更，风险进一步放大）。
- **根因分析**：反向代理部署场景下，这些头应由网关覆写后才可信，但框架未提供「哪些代理可信、哪层头可信」的统一抽象，各中间件各自取用客户端头。
- **修复建议**：新增 `trusted_proxies` 配置（CIDR 列表，参照 Laravel），仅当直连地址属于可信网段时采信代理头，否则一律用 `REMOTE_ADDR`；Feature 的 `X-User-Id` / `X-Tenant-Id` 同样要求可信网关覆写，或改为仅从鉴权上下文（`auth_user_id`）取桶键。

### H5. AccessLogMiddleware 原始 query string 未脱敏写入访问日志

- **位置**：`src/Http/Middleware/AccessLogMiddleware.php:87-88`
- **问题描述**：`'uri' => $path . '?' . $query`、`'query' => $query` 将原始查询串直接落日志。`?token=xxx`、`?password=xxx`、`?sign=xxx` 等敏感参数随请求写入日志文件，长期留存形成泄密面。AuditService 已具备 `maskQuery()`（AuditService.php:203-224）脱敏能力，访问日志缺失同等处理。
- **根因分析**：访问日志实现未复用/未实现 query 脱敏逻辑，配置 `logging.access_log` 亦无 mask 开关。
- **修复建议**：复用与 AuditService 一致的敏感字段名集合，对 `$query` 脱敏后再写 `uri`/`query`；或提供 `mask_params` 配置项（默认含 token/password/sign 等）。

### H6. LimiterFactory memcached 分支错误读取 redis 配置段

- **位置**：`src/Http/RateLimit/LimiterFactory.php:68-73`
- **问题描述**：`memcached` 分支构造 Limiter 时读取的是 `$this->config['redis']['host']` / `['port']`（默认 `127.0.0.1:11211`）。而 `config/limiting.php` 只有 `redis` 段（含 `prefix`），**没有 memcached 段**——memcached 后端的地址根本无法通过配置指定，永远落到默认本机。典型复制粘贴残留。
- **根因分析**：分支实现从 redis 段复制时未改配置键；配置侧亦未定义 `memcached` 段，双向缺失。
- **修复建议**：新增 `limiting.memcached.host/port` 配置，memcached 分支读取自身段；若暂不支持该后端，应在 `resolve()`（144-148 行附近）对 `memcached` 显式抛「未支持」而非静默错连。

### H7. redis.prefix 已定义但从未生效，Redis 后端无限流键隔离

- **位置**：`config/limiting.php:49`（`'prefix' => env('REDIS_PREFIX', 'kode:limiting:')`）；`src/Http/RateLimit/LimiterFactory.php`（redis 分支及全局均无 `prefix` 引用）
- **问题描述**：配置声明了 redis 键前缀，但 `LimiterFactory` 构造 redis Limiter 时未传 `prefix`（也不存在于任何分支参数中）。多业务/多环境共享同一 Redis 时，不同应用的限流键互相冲突：A 业务某路由的计数可能命中 B 业务同路径的计数，限流错乱（被误限或漏限）。
- **根因分析**：配置键先于实现落地，实现未接线。
- **修复建议**：redis 分支构造 Limiter 时传入 `($this->config['redis']['prefix'] ?? 'kode:limiting:')`；同步检查 kode/limiting 供应商是否支持前缀参数，不支持则在键名前手动拼接。

### H8. CircuitBreakerMiddleware 熔断键未归一化，动态路径可绕过熔断

- **位置**：`src/Resilience/CircuitBreakerMiddleware.php:122-137`（`resolveName()`，默认 `derive_from=path` 时返回原始 `getUri()->getPath()`）
- **问题描述**：熔断按**原始路径**分段（`/users/42`、`/users/43`…各自独立电路）。对比同框架的限流中间件已把数字段归一为 `{id}`（`RateLimitMiddleware::routeKey()`），熔断却未做归一化——攻击者/异常代码用不同 ID 反复打下游故障接口时，每次都是「新电路」，熔断器永不打开，下游被持续打爆；正常业务的路由路径参数化时熔断保护也完全失效。
- **根因分析**：`resolveName` 直接取 path，未套用限流同款的路由模板归一化（`RateLimitMiddleware` 有 `routeKey` 逻辑可复用，见其 `key()`/`routeKey()`）。
- **修复建议**：熔断键默认改为「路由匹配模板 + 方法」（复用 `RouteResolver` 匹配结果或 `router->match()` 的路由名/pattern），与限流键归一化策略保持一致；仅当显式 `derive_from` 指定原始 path 时才保留现状。

---

## 四、中（Medium）

### M1. AuditSink / AccessLogSink 进程级静态队列在 fiber 并发下存在竞态窗口

- **位置**：`src/Security/Audit/AuditSink.php:30`（`private static array $queue`，`emit()` 热路径 push）；`src/Logging/AccessLogSink.php:29-36`（同型静态队列）
- **问题描述**：队列是进程级静态数组，热路径 `emit()` 追加、离路径 `flush()` 整批取走。PHP 单线程下若中间无挂起点则安全；但框架面向 fiber 常驻模型（Swoole/Workerman/kode-fibers），当 `flush()` 的 logger 写入发生挂起/切换、或 `emit` 与 `flush` 在不同 fiber 交错时，可能出现记录丢失、重复或读取未完成写入的数组。目前无锁、无 `Fibers` 互斥保护。
- **根因分析**：静态共享 + 无同步原语，未考虑 fiber 调度挂起点。
- **修复建议**：入队/出队加 fiber 安全互斥（kode/fibers 若提供锁则用之），或将队列改为 per-fiber 缓冲 + 请求结束时合并；至少补充并发压测用例验证。

### M2. config/app.php 被多次 require，顶层副作用可能重复执行

- **位置**：`src/Application.php:314`（`preloadAppConfig()` 用 `require` 而非 `require_once`）；调用链：`providers()`（196 行）→ `preloadAppConfig()`、`runtimeModes()`（294 行）→ `preloadAppConfig()`、`checkProviderCoverage()` → `providers()`（再经一次 `preloadAppConfig()`），至多 require **3 次**
- **问题描述**：`config/app.php` 在引导期被多次 `require`。若该文件含顶层副作用（`define()`、函数声明、静态变量初始化、连接建立），会重复执行——`define()` 第二次会触发 PHP Warning（constant already defined）。常规纯数组返回的配置不受影响，但这是引导期隐患。
- **根因分析**：`preloadAppConfig()` 未做调用缓存（未先查容器/属性再加载），各调用方各自触发加载。
- **修复建议**：`preloadAppConfig()` 内缓存结果（`private ?array $preloaded = null`，首次加载后复用），或改用 `require_once`；同时将 `providers()`/`runtimeModes()` 统一经同一缓存入口取值。

---

## 五、低 / 观察项

1. **TraceMiddleware 冗余采样标记**（`src/Observability/Middleware/TraceMiddleware.php`）：外层已 `decideSampled()` 决策，内层 `start(..., sampled: true)` 再强制采样，实际不改变结果——无害冗余，不建议按缺陷处理（记录备查）。
2. **ProcessManager 多 worker 等待循环空转**（`src/Process/ProcessManager.php`）：`wait(null, true)` 返回 `pid=0` 时仅 `usleep(10ms)` 空转、不缩减 `children`，依赖 `stop()` 置 `forking=false` 才能退出。行为正确但存在忙等窗口，建议按需优化为「有退出子进程才继续轮询」。
3. **JwtGuard 强转 kode/jwt 返回结构未验证**（`src/Auth/JwtGuard.php`）：`(string) KodeJwt::guard()->issue(...)['token']` 依赖 vendor 返回 `['token' => string]`，未在本仓库内验证 kode/jwt 实际结构（vendor 依赖，静态审读无法定论）。**待验证项**。
4. **ConnectionCleanupMiddleware 异常静默吞**（`src/Http/Middleware/ConnectionCleanupMiddleware.php`）：连接回收异常全部静默吞掉，出问题时无任何可观测信号；建议至少 debug 级记录。
5. **Messaging::bus() 实例缓存语义依赖 vendor**：`src/Messaging/Messenger.php` 每次 `Messaging::pubsub($driver)`，是否复用同驱动实例取决于 kode/messaging 实现——**待验证项**，若每次重建则高频消息路径有重复连接开销。

---

## 六、修复路线建议

| 优先级 | 事项 | 对应条目 | 备注 |
|---|---|---|---|
| P0（上线前必修） | 锁看门狗禁止重执行业务 | C1 | 双击风险最高，需测试覆盖 |
| P0 | pcntl 超时调度器补 `pcntl_alarm($secondsInt)` | C2 | 一行修复 + 单测 |
| P1 | 幂等 file 后端原子占位 | H3 | 直接关系幂等语义 |
| P1 | 幂等重放恢复完整响应头 | H2 | 关系登录/下单响应一致性 |
| P1 | 受信代理机制（限流/审计/灰度统一采信边界） | H4 | 安全面最大，建议先补 trusted_proxies |
| P1 | 审计用户身份按请求边界清除 | H1 | 合规记录完整性 |
| P2 | 熔断键归一化、访问日志脱敏、限流 prefix/memcached 配置修复 | H5-H8 | 均可本地自测 |
| P2 | 静态队列加锁、config/app.php 缓存 | M1-M2 | 常驻模型稳定性 |

## 七、审计边界与局限

- 本报告全部为**静态审读结论**，未在运行时验证（环境无 PHP 可执行文件）；C1/C2/H3 等关键缺陷建议修复后补跑单元测试再上线。
- vendor 依赖（kode/http、kode/fibers、kode/jwt、kode/messaging、kode/process、kode/limiting）仅按契约推断，未验证其内部实现；影响结论的元素已在正文标注「待验证项」。
- 未逐行覆盖：`Resilience/Retry.php`、`Timeout.php`、`Breaker.php`、`FiberBreaker.php`、Backoff 策略类的无中间件部分；`Tenant/HeaderTenantResolver`、`SubdomainTenantResolver`、`TenantStorageManager`；`Observability/Metrics` 三件与 OtlpMapper；`Health/HealthChecker`、`Scheduling` 全套、`Console/Command`。这些模块如需完整结论可第二轮补充审计。