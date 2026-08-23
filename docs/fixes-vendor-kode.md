# vendor/kode 包修改指引（可选加固）

> 对应审计报告：`audit-report-2026-08-22.md`
> 框架版本：v0.8.45（`src/Application.php` 的 `VERSION` 已同步）
> 适用范围：**仅限 `vendor/kode/` 下的第三方包**。框架源码（`src/`、`config/`）已在 v0.8.42 全部修复，
> 本指引中的改动是**可选加固**——不改 vendor，框架也能正确工作（见「五、框架侧规避对照」）。
>
> **2026-08-23 更新**：`kode/limiting` **2.2.0** 已把 A/B/C 三处改动全部合入上游；`kode/database`
> **1.15.7**（2026-08-23 发布）已把「八」的 `PdoConnection` 语句缓存/去探活/断连重试合入上游；
> **两个包均无需再手动修改 vendor**，`composer update` 即可获得修复（见对应节末尾的验证记录）。

---

## 一、结论概览

| 包 | 版本 | 是否建议修改 | 原因 |
| --- | --- | --- | --- |
| `kode/limiting` | 2.1.0 → **2.2.0（已修复）** | **无需修改** | 2.2.0 已把静态工厂 prefix 硬编码 / 不收 prefix / storeFromArray 缺 HA 分支三处全部合入上游 |
| `kode/http` | **3.4.6（部分已修复）** | **2 处待反馈上游** | §3.5（`json()` rawBody 快路径）/§3.6（`getBody()` 非破坏性）已合入 3.4.6/3.4.3；仍有 `SwooleServerAdapter` emit 直发与 `StringStream` 纯内存流共 2 处由框架补丁承载，见「七」 |
| `kode/database` | **1.15.7（全部已修复）** | **无需修改** | B 缺陷（非 Swoole 下池不可用）已由 1.15.6 修复（`PoolManager` 降级 + `ScopedConnection`）；`PdoConnection` 语句缓存/去探活/断连重试由 **1.15.7** 合入（见「八」），框架侧 database 补丁已移除 |
| `kode/jwt` | — | 无需修改 | 审计观察项 3 经核对为误报（`issue()` 返回结构与 guard 用法匹配） |
| `kode/fibers` | — | 无需修改 | 非协程上下文同步执行是刻意语义；框架已通过 `Scheduler::inCoroutine()` 规避 |
| `kode/messaging` | — | 待验证 | 审计观察项 5（实例缓存语义）需在真实运行环境验证后才能下结论 |

---

## 二、kode/limiting 修改指引

> ### ✅ 2026-08-23：本节 A/B/C 三处已由上游 **v2.2.0**（2026-08-22 发布）合入，无需手动改动
>
> 实测核对（PHP 8.3.32 + 真实 redis-server 7.0.15，框架 v0.8.44）：
> 1. `Limiter::redis()` 新增 `string $prefix = 'kode:limiting:'` 参数，standalone / sentinel / cluster
>    三分支全部透传（对应下文修改点 A）；
> 2. `Limiter::memcached()` 新增 `$prefix` 参数并传给 `MemcachedStore::create($host, $port, $prefix)`
>    （对应修改点 B）；
> 3. `storeFromArray()` 的 REDIS 分支按 `config['mode']` 分发 standalone / sentinel / cluster，
>    prefix 全程受控（对应修改点 C）——sentinel 分支实测传参为
>    `createSentinel($sentinels, $masterName, ..., 'myapp:')`（自定义前缀已透传）；
>    standalone 分支自定义 prefix 实测落库：配置 `myapp:` → Redis 键 `myapp:limiter:bucket:…`。
>
> 以下原文保留作为「上游 2.2.0 相对 2.1.0 改动对照」存档。

### 修改点 A：`Limiter::redis()` 让前缀可控

**文件**：`vendor/kode/limiting/src/Limiter.php`
**位置**：`redis()` 方法（第 208–228 行）

**现状与根因**：

```php
$store = match ($mode) {
    RedisMode::STANDALONE => RedisStore::create($host, $port, 'kode:limiting:', $password, $database),  // 第 222 行：prefix 硬编码
    RedisMode::SENTINEL    => RedisStore::createSentinel($sentinels, $masterName, $password, $database), // 第 223 行：未传 prefix，走默认
    RedisMode::CLUSTER     => RedisStore::createCluster($clusterNodes, $password),                      // 第 224 行：未传 prefix，走默认
};
```

`RedisStore::create()` / `createSentinel()` / `createCluster()` 三个方法其实都支持 `$prefix` 参数
（`RedisStore.php:51-58`、`83-90`、`131-136`，默认值均为 `'kode:limiting:'`），但 `Limiter::redis()`
这个静态工厂把它们写死或省略，导致**任何场景都无法自定义前缀**——多租户共用同一 Redis 时可能发生
限流键串用。

**修改方法**（两步）：

1. 在方法签名（第 208–220 行）中加一个 `$prefix` 参数，放在 `$masterName` 之后：

```php
public static function redis(
    LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
    int $capacity = 10,
    float $refillRate = 1.0,
    string $host = '127.0.0.1',
    int $port = 6379,
    ?string $password = null,
    int $database = 0,
    RedisMode $mode = RedisMode::STANDALONE,
    array $sentinels = ['127.0.0.1:26379'],
    string $masterName = 'mymaster',
    string $prefix = 'kode:limiting:',   // ← 新增
    array $clusterNodes = ['127.0.0.1:7000']
): self {
```

2. 把 match 三行改为传入 `$prefix`：

```php
$store = match ($mode) {
    RedisMode::STANDALONE => RedisStore::create($host, $port, $prefix, $password, $database),
    RedisMode::SENTINEL    => RedisStore::createSentinel($sentinels, $masterName, $password, $database, $prefix),
    RedisMode::CLUSTER     => RedisStore::createCluster($clusterNodes, $password, $prefix),
};
```

新增参数放在 `$masterName` 之后、`$clusterNodes` 之前，不与任何现有调用方的位置参数冲突
（调用方按命名参数或省略末位参数时无影响；按位置传参且恰好传满到 `$clusterNodes` 的调用方不受影响，
因为新参数在它之前插入了默认值——若调用方按位置传了 `$clusterNodes`，需要同步调整）。

**验证**：改后 `composer dump-autoload`（若包无 PSR-4 变更可跳过），任一调用 `Limiter::redis(..., prefix: 'myapp:')`
确认生成的 Redis 键以 `myapp:` 开头。

---

### 修改点 B：`Limiter::memcached()` 支持前缀

**文件**：`vendor/kode/limiting/src/Limiter.php`
**位置**：`memcached()` 方法（第 233–246 行）

**现状与根因**：第 243 行 `MemcachedStore::create($host, $port)` 未传 prefix，`MemcachedStore::create()`
第三参虽支持（`MemcachedStore.php:39-42`）但永远收缩到默认值。

**修改方法**：

```php
public static function memcached(
    LimiterType $limiterType = LimiterType::TOKEN_BUCKET,
    int $capacity = 10,
    float $refillRate = 1.0,
    string $host = '127.0.0.1',
    int $port = 11211,
    string $prefix = 'kode:limiting:'   // ← 新增
): self {
    return new self(
        StoreType::MEMCACHED,
        $limiterType,
        MemcachedStore::create($host, $port, $prefix),   // ← 第 243 行改为传 $prefix
        new LimiterConfig($capacity, $refillRate)
    );
}
```

**验证**：改后调用 `Limiter::memcached(..., prefix: 'myapp:')` 确认键名前缀生效。

---

### 修改点 C（可选，低优先）：`storeFromArray()` 支持 sentinel / cluster

**文件**：`vendor/kode/limiting/src/Limiter.php`
**位置**：`storeFromArray()`（第 538–567 行）

**现状**：store 数组路径（`Limiter::create($type, $config, $storeArray)` 内部）只解析出
`RedisStore::create()`（standalone，第 545–551 行），**数组中即使写了 `mode: sentinel|cluster` 也会被忽略**
——低配 Redis HA 的限流键永远不会带前缀或连 HA 拓扑。

**修改方法**（需要 `use Kode\Limiting\Enum\RedisMode;`，并把 `StoreType::REDIS` 分支改为）：

```php
StoreType::REDIS => match (RedisMode::tryFrom((string) ($config['mode'] ?? 'standalone')) ?? RedisMode::STANDALONE) {
    RedisMode::SENTINEL => RedisStore::createSentinel(
        array_map('strval', (array) ($config['sentinels'] ?? ['127.0.0.1:26379'])),
        (string) ($config['master_name'] ?? 'mymaster'),
        isset($config['password']) ? (string) $config['password'] : null,
        (int) ($config['database'] ?? 0),
        (string) ($config['prefix'] ?? 'kode:limiting:'),
    ),
    RedisMode::CLUSTER => RedisStore::createCluster(
        array_map('strval', (array) ($config['cluster_nodes'] ?? ['127.0.0.1:7000'])),
        isset($config['password']) ? (string) $config['password'] : null,
        (string) ($config['prefix'] ?? 'kode:limiting:'),
    ),
    default => RedisStore::create(
        (string) ($config['host'] ?? '127.0.0.1'),
        (int) ($config['port'] ?? 6379),
        (string) ($config['prefix'] ?? 'kode:limiting:'),
        isset($config['password']) ? (string) $config['password'] : null,
        (int) ($config['database'] ?? 0),
    ),
},
```

> ⚠️ 修改 C 会改变 `storeFromArray()` 对 `mode` 字段的处理语义。若你同时在使用旧版
> `Kode\Limiting\Manager` 或其它以数组注入 store 的入口，请先核对它们传入的键名再改。
> **框架（v0.8.42）不依赖此修改**——框架的 HA 路径直接构造 `RedisStore` 实例（见「五」），
> 此改动仅让包自身对数组输入的语义更完整。

---

## 三、已核对、无需修改的包

### kode/jwt（观察项 3 证伪）

审计曾怀疑 guard 的 token 校验与 `issue()` 返回结构不一致。经核对：`issue()` 返回
`['token' => string, 'expires_in' => int, 'refresh_ttl' => int]`，与框架 `JwtGuard` 的用法完全匹配，
**不存在问题，不要改动**。

### kode/fibers（观察项 4 / C1 相关）

`Fibers::go()` 在非协程上下文**同步阻塞执行**是包的设计语义，非缺陷。原 C1（锁看门狗续期协程
依赖此语义导致续期无法并行）已在框架侧修复：`LockWatchdog::fiberAvailable()` 改用
`Scheduler::inCoroutine()` 判断，`fiberTicker()` 在协程内用 `Scheduler::current()->go()` 并行挂载。
**vendor 无需改动**。

---

## 四、待验证项

### kode/messaging（观察项 5）

消费端实例缓存语义（同一 consumer 实例是否被多协程共享、消费状态是否串用）需在真实运行环境
（装有 PHP 8.x + 全部扩展）跑通消息收发链路才能确认。框架本轮的修复不涉及该包；
如你在生产观察到消息重复/串扰，再回到这一项排查。

---

## 五、框架侧规避对照（v0.8.42 → v0.8.44）

| 原缺陷 | 框架规避方式 | 对应文件 |
| --- | --- | --- |
| H6 memcached 误读 redis.host/port | `build()` 按 driver 分支明确读取独立 `memcached` 段，`memcachedStore()` 返回带 prefix 的 store 数组 | `src/Http/RateLimit/LimiterFactory.php` |
| H7 redis.prefix 在 standalone 不生效 | `redisStore()` 改走 `Limiter::create(...)` + store 数组，数组内含 `prefix` | 同上 |
| H7* redis HA（sentinel/cluster）prefix 不受控 | v0.8.42 曾用 `redisHA()` 直接构造 `RedisStore::createSentinel/createCluster` 规避；**v0.8.43 随上游 2.2.0 移除该手搓路径**，统一走 `redisStore()`（store 数组 + `mode` 字段，`storeFromArray` 原生分发三分支，prefix 全程受控） | 同上 |
| C1 看门狗续期协程同步阻塞/异常回退 | `fiberAvailable()` 用 `Scheduler::inCoroutine()`、`fiberTicker()` 用 `Scheduler::current()->go()` 并行挂载，work 异常原样上抛 | `src/Lock/LockWatchdog.php` |
| C2 pcntl 超时从不启动 | `pcntl_alarm($secondsInt)` 真正启动计时，`$op()` 返回后补抛 `TimeoutExceeded` | `src/Resilience/TimeoutScheduler/PcntlTimeoutScheduler.php` |
| 幂等重放 Content-Type 重复 | `rebuild()` 回放持久化头时跳过 `content-type`（避免与构造参数二次追加） | `src/Idempotency/IdempotencyMiddleware.php` |
| 限流热路径冗余 | ① `process()` 内 `clientIp()` 每请求只解析一次（keyContext 与 fallback 键共用）；② `TrustedProxies::clientIp()` 空受信列表走直通快路径；③ `LimiterFactory` 构造时预计算 store 签名段（每请求免 match/兜底取值） | `src/Http/Middleware/RateLimitMiddleware.php`、`src/Http/Support/TrustedProxies.php`、`src/Http/RateLimit/LimiterFactory.php` |

> `*`：标星项为审计后追加确认的衍生问题。A/B/C 三处 vendor 修改点对框架行为没有影响
> （框架始终走 store 数组 / 直接构造路径）；改它们的价值在于让 `kode/limiting` 包本身对其它
> 使用方更正确——2.2.0 已全部合入，框架 v0.8.43 顺势删除手搓 HA 路径，减少维护面。

---

## 六、修改后收尾

> **本节已由上游 2.2.0 直接消除**（2026-08-22 发布，提取全部 A/B/C 修复）——**无需手动改
> vendor**。保留以下内容仅作流程参考：若未来 vendor 内出现新的临时改动，仍按此收尾：

1. 改完 `vendor/kode/limiting/src/Limiter.php` 后执行 `composer dump-autoload`（仅当包有 autoload 映射变更时必做；PSR-4 默认不必须）。
2. 在装有 PHP 的环境跑一次静态检查：`php -l vendor/kode/limiting/src/Limiter.php`。
3. 跑限流相关测试（框架 `tests/` 内的限流用例），并做一次多租户/多应用共用 Redis 的联调验证前缀隔离。
4. 不建议把 `vendor/` 下的修改提交进 git 或打进发布包（会被 `composer install` 覆盖）；若团队需要固化，应给 `kode/limiting` 提 PR 或引入 patch（如 `cweagans/composer-patches`）。

---

## 七、kode/http 待反馈上游（2 处，由框架补丁承载）

> **现状（2026-08-23）**：`kode/http` 已升级 3.4.6；§3.5（`Response::json()` rawBody 快路径）与
> §3.6（`getBody()` 非破坏性）已由上游合入（见 `docs/kode-http-change-spec.md` 状态块）。下述 2 处仍由
> 框架侧 `composer.json extra.patches` 固化，**建议按下方 diff 反馈 kode/http 包仓库**，合入后框架侧
> 对应补丁即可删除。
>
> 修改方式（在 kode/http 仓库根目录操作，改完跑 `composer test` 与框架全量测试复核）：

### 7.1 `SwooleServerAdapter`：emit 响应取内部字符串体直发

- 文件：`src/Server/SwooleServerAdapter.php`
- 位置：`$server->start()` 回调内 `$swooleResponse->end(...)` 处（框架补丁 hunk 基于 `@@ -34,7 +34,11 @@`）
- 背景：kode 自研响应经 `json()` 已持有内部字符串体（rawBody，3.4.6 起），但 `end()` 仍走
  PSR-7 `getBody()->getContents()` 接口分发，每请求多一次 Stream 物化。直取 `getBodyString()`
  可跳过该分发（兼容非 kode 响应，fallback 原路径）。
- 原代码：

```php
$swooleResponse->end($response->getBody()->getContents());
```

- 新代码：

```php
// kode 自研响应直接取内部字符串体，避开 PSR-7 getBody()->getContents() 接口分发
$body = $response instanceof \Kode\Http\Response
    ? $response->getBodyString()
    : $response->getBody()->getContents();
$swooleResponse->end($body);
```

- 验证：Swoole 环境回归 `kode/process` worker 响应字节与旧实现一致；框架 `tests/HttpBridgeTest.php`
  全绿。框架侧补丁：`patches/kode-http-response-optimize.patch`（合入上游后删除该补丁与
  `composer.json extra.patches` 对应条目）。

### 7.2 `StringStream` + `Stream::create`：小体响应纯内存持有

- 文件：`src/Psr7/Stream.php`（新增类 `src/Psr7/StringStream.php`）
- 位置：`Stream::create(string $content = '', string $mode = 'r+')` 开头
- 背景：`Stream::create()` 默认 `fopen('php://temp')`，每响应一次临时流分配 + 两次整段拷贝
  （fwrite 写入、stream_get_contents 读回）；对 ~1KB 响应体该开销被放大 ~100×，是 kode 响应管线
  相对 webman（体即字符串）偏慢的构成之一。新增纯内存 `StringStream`（实现 PSR-7
  `StreamInterface`，`getContents()`/`__toString()` 直接返回持有的字符串），`create()` 对
  **≤1MB**（含空串）返回 `StringStream`，超限回落 `php://temp` 保留大文件落盘能力。
- `Stream.php` 改动（原代码 → 新代码，插在 `create()` 方法体首部）：

```php
$resource = fopen('php://temp', $mode);
if ($resource === false) {
    throw new RuntimeException('无法创建临时流');
```

```php
// 小体量正文直接内存持有（StringStream），消除每响应 fopen('php://temp') + 两次整段
// 拷贝（fwrite 写入、stream_get_contents 读回）的热路径开销；超过 1MB 仍回落 php://temp。
if ($content === '' || strlen($content) <= 1_048_576) {
    return new StringStream($content);
}

$resource = fopen('php://temp', $mode);
if ($resource === false) {
    throw new RuntimeException('无法创建临时流');
```

- 新增 `src/Psr7/StringStream.php` 完整源码：见框架仓库 `patches/kode-http-stringstream.patch`
  （该补丁含新增文件的完整内容，可直接 `git apply`；PSR-4 命名空间 `Kode\Http\Psr7` 新文件
  无需 `composer dump-autoload`）。
- 验证要点：`Stream::create()` 返回的流必须通过 PSR-7 契约——`seek()`/`rewind()` 抛
  `RuntimeException` 是**刻意语义**（`isSeekable() === false`），其余 `read`/`getContents`/
  `getSize`/`getMetadata` 行为要与 php://temp 一致；跑 kode/http 自带的 PSR-7 契约测试 +
  响应管线冒烟。框架侧补丁：`patches/kode-http-stringstream.patch`。

---

## 八、kode/database 待反馈上游（1 处，由框架补丁承载）

> ### ✅ 2026-08-23：本节已由上游 **v1.15.7**（2026-08-23 发布）合入，无需手动改动
>
> 实测核对（PHP 8.3.32 + pdo-sqlite，框架 v0.8.45）：
> 1. `PdoConnection` 具备 `stmtCache`（预编译语句缓存，上限 `STMT_CACHE_LIMIT`=256，`disconnect()` 清空）；
> 2. `ensureConnected()` 已连接即复用，**不再每次查询发 `SELECT 1` 探活**（`isConnected()` 仅保留
>    显式探测用途）；
> 3. `select`/`insert`/`update`/`delete` 捕获 `PDOException` 后 `disconnect()` 并重试一次（断连重试）。
> 4. 框架 v0.8.45 已**移除 `patches/kode-database-pdoconnection.patch` 与 `composer.json extra.patches`
>    中 `kode/database` 条目**，vendor 为纯净 1.15.7；`benchmarks/database-bench.php` 实测语句缓存
>    命中 SELECT 802.9 ns/op vs 原生每次 prepare 1821.5 ns/op（**-56%**，见 `benchmarks/database-bench.md`）。
>
> 以下原文保留作为「上游 1.15.7 相对 1.15.6 改动对照」存档。

### 8.1 `PdoConnection`：预编译语句缓存 + 去掉每查一次 SELECT 1 探活 + 断连重试

- 文件：`src/Connection/PdoConnection.php`
- 背景：常驻内存场景下（kode/process Native），旧实现每次查询都 `ensureConnected()->isConnected()`
  发一次 `SELECT 1` 探活（**每查 1 次多 1 次 DB 往返**，是常驻内存下最大的性能拖累），且每次
  `prepare($sql)` 都走一次 PREPARE 网络往返。改动后：`ensureConnected()` 仅判"已连接即复用"，
  失效由 PDOException 在执行语句时暴露、捕获后重连重试一次（行为不变、更省）；同一 SQL 预编译
  语句按文本缓存（上限 256，`disconnect()` 时清空），后续请求复用 PDOStatement 直接 execute。
  该语义对齐 Doctrine/Laravel 的语句缓存。
- 改动点（原作者改动，可直接抄）：
  1. 属性区新增 `protected array $stmtCache = [];`（注释说明用途与上限）。
  2. `ensureConnected()` 首行由 `if ($this->pdo !== null && $this->isConnected())` 改为
     `if ($this->pdo !== null)`（**不再每次探活**）。
  3. 新增 `prepareStatement(string $sql)`：命中缓存直接返回；否则 `ensureConnected()->prepare($sql)`，
     缓存后返回（缓存满 256 条时不缓存、仍可用）。
  4. `select`/`insert`/`update`/`delete` 四个方法改为：`try { …prepareStatement… } catch (PDOException $e) {
     $this->disconnect(); …prepareStatement 重试一次 }`。
  5. `disconnect()` 尾部追加 `$this->stmtCache = [];`。
- 完整 diff：已由上游 1.15.7 合入（`vendor/kode/database/src/Connection/PdoConnection.php`）；
  历史补丁 `patches/kode-database-pdoconnection.patch` 已随框架 v0.8.45 删除。
- 验证要点：`kode/database` 自带的 TransactionTest/PdoConnectionTest 全绿；框架 `src/Database/ConnectionPool`
  Native 冒烟（select/insert/update/delete 各一次 + 断连重试路径）；`disconnect()` 后语句缓存清空、
  再查询自动重连。

---

*本指引由静态审读 + 源码级核对产出。v0.8.45（2026-08-23）起已在 PHP 8.3.32 + 真实
redis-server 7.0.15 环境对限流链路与数据库链路做过运行验证（框架 425 测试全绿、限流三后端与
数据库语句缓存实测见 `benchmarks/limiting-bench.md` 与 `benchmarks/database-bench.md`）；改动点行号基于当时
`vendor/kode/limiting` / `vendor/kode/database` 版本。*