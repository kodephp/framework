# 高级用法

本篇面向已跑通「入门指南」、需要深入路由、安全、扩展与部署的开发者。
所有示例基于 `app/` 目录下的业务代码，框架源码（`src/`）无需改动。

---

## 1. 路由：分组 / 嵌套 / 中间件 / 别名

`kode/http` 已原生支持：前缀分组、嵌套分组、路由级中间件、命名路由（别名）、参数正则、默认参数、URL 生成、直接返回。

### 1.1 基础写法

```php
use Kode\Http\App;
use App\Http\Controllers\UserController;

return function (App $app): void {
    $app->get('/users/{id:\d+}', fn($req) => resolve(UserController::class)->show($req))
        ->name('user.show');                 // 命名路由（别名）

    $app->post('/users', fn($req) => resolve(UserController::class)->store($req));
    $app->any('/ping', fn() => ['pong' => true]);
};
```

- 路由参数：`{id:\d+}` 表示只匹配数字；`{slug}` 匹配任意非 `/` 段。
- 取参：控制器里 `$this->input('id')`（或 `Kode\Http\Request::param('id')`）。
- 命名路由后可用 `route('user.show', ['id' => 1])` 反向生成 URL（框架全局助手，底层 `App::url()`）；在拥有 `$app` 的闭包里也可 `$app->url('user.show', ['id' => 1])`。

### 1.2 分组（前缀 + 中间件）

```php
$app->group('/api', function (App $app): void {
    $app->get('/profile', fn() => resolve(UserController::class)->profile());
    $app->post('/avatar', fn() => resolve(UserController::class)->avatar());
}, [new \App\Http\Middleware\ApiGuard()]);   // 该组统一挂中间件
```

### 1.3 嵌套分组

分组回调里可以继续 `group()`，前缀与中间件会**逐层叠加**：

```php
$app->group('/api', function (App $app): void {
    $app->group('/v1', function (App $app): void {
        $app->get('/posts', fn() => /* ... */);   // 实际路径 /api/v1/posts
    }, [new \App\Http\Middleware\VersionGuard()]);
}, [new \App\Http\Middleware\ApiGuard()]);
```

### 1.4 路由级中间件（单条）

```php
$app->get('/me', fn() => resolve(UserController::class)->me())
    ->middleware(new \Kode\Framework\Http\Middleware\AuthMiddleware());
```

框架已内置中间件：`AuthMiddleware`（JWT 鉴权）、`RateLimitMiddleware`（限流）、`CorsMiddleware`、`SecurityHeadersMiddleware`、`RequestIdMiddleware`、`LocaleMiddleware`。

### 1.5 直接返回 vs 统一信封

- `return ['k'=>'v']` 或 `return $this->ok(...)` → 走**统一信封** `{code,msg,data}`。
- `return $this->response($data)->status(201)->header('X-A','b')` → 仍是信封，但你能加状态码/头。
- 想**完全绕过**信封（比如返回文件流、裸 JSON）？直接返回 `Kode\Http\Response`：

  ```php
  use Kode\Http\Response;
  return Response::make($rawBody, 200, ['Content-Type' => 'application/json']);
  ```

### 1.6 查看全部路由

```bash
php bin/kode console route:list              # 全量，按 URI 段分组+数量
php bin/kode console route:list --compact    # 仅看「分组 → 数量」摘要
php bin/kode console route:list --group=api  # 只看某分组
php bin/kode console route:list --method=POST
php bin/kode console route:list --source=app
```

> 大项目路由很多时，用 `--compact` 先看分组概览；插件（见 §9）的路由也会被标记来源 `plugin:<name>`。

### 1.7 属性路由（双模型并存）

框架同时支持两套模型，按喜好任选或混用：

| 模型 | 写法 | 适用 |
| --- | --- | --- |
| **属性路由**（约定优于配置） | 在控制器类/方法上用 `#[RouteController]` / `#[Get]` 声明 | 多应用自动发现、与方法就近、可读性高 |
| **显式路由**（routes.php） | 手动 `App::get/post/route(...)` | 需要精细命名、覆盖、或聚合编排的场景 |

属性路由由启动时自动扫描 `app/Http/Controllers` 完成（见 `config/routes.php` 的 `attributes`），**无需在 routes.php 逐条手写**。它是「多应用自动路由匹配」的落地方式；方法上的 `name` 仍支持「可指定命名方法」（命名路由反向生成 URL）。

```php
use Kode\Framework\Http\Attributes\Controller as RouteController;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;
use Kode\Framework\Http\Attributes\Delete;
use Kode\Framework\Http\Controller;

#[RouteController(prefix: '/products')]          // 类级前缀 + 可选 middleware
final class ProductsController extends Controller
{
    #[Get('')]                                    // GET /products
    public function index() { /* ... */ }

    #[Get('/{id:\d+}', name: 'product.show')]     // 命名路由
    public function show() {
        $id = (int) $this->param('id');           // 路由参数用 $this->param()
        /* ... */
    }

    #[Post('')]
    public function store() { /* ... */ }

    #[Delete('/{id:\d+}')]
    public function destroy() { /* ... */ }
}
```

可用属性：`#[Controller(prefix, middleware)]`、`#[Route(methods, path, name, middleware)]`，以及语法糖 `#[Get]` `#[Post]` `#[Put]` `#[Delete]` `#[Patch]` `#[Any]`。

- 类级 `middleware` 与 方法级 `middleware` 自动合并；
- 路径 = 类前缀 + 方法路径（方法路径留空即取类前缀，如 `GET /products`）；
- `route('product.show', ['id' => 1])` 反向生成 `/products/1`（见 §1.1）；
- 开关：`config/routes.php` 的 `attributes.enabled`（环境变量 `ROUTE_ATTRIBUTES`，默认开）。

> 属性路由先注册、显式路由后注册；若路径冲突，routes.php 的显式条目覆盖属性路由。`route:list` 两种都会列出，默认仅主应用、无插件来源。

---

## 2. 统一响应与异常映射

### 2.1 信封结构

```json
{ "code": 0, "msg": "ok", "data": {} }
```

`Resp` 提供：`ok()` / `fail()` / `paginate()` / `make()`。控制器封装了 `$this->ok()` / `$this->fail()` / `$this->response()`。

### 2.2 异常自动转信封（核心机制）

框架的立场是：**封装好的统一响应更好** —— 你不必在每个方法里手写 try/catch 拼格式。框架在全局异常处理器里把已知异常自动转成信封：

| 异常 / 场景 | 信封 code | HTTP |
| --- | --- | --- |
| `ValidationException`（校验失败） | `E422` | 422 |
| 路由未匹配 | `E404` | 404 |
| 方法不允许 | `E405` | 405 |
| `AuthMiddleware` 拦截 | `E401` | 401 |
| `RateLimitMiddleware` 拦截 | `E429` | 429 |
| 其它未捕获异常 | `E500` | 500 |

例子：业务里直接抛领域异常即可，格式交给框架：

```php
if ($user === null) {
    throw new \RuntimeException('用户不存在');   // → {"code":"E500","msg":"用户不存在"}
}
```

### 2.3 自定义业务异常（推荐做法）

想要自定义 `code`（而不是通用 `E500`）？定义带业务码的异常，并在 `HttpServiceProvider` 的异常处理器中映射：

```php
final class BizException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $bizCode = 'E400',
        int $http = 400,
    ) {
        parent::__construct($message, $http);
    }
}
```

然后在 `src/Providers/HttpServiceProvider.php` 的 `boot()` 异常处理闭包里加分支：

```php
if ($e instanceof \App\Exceptions\BizException) {
    return Resp::fail($e->getMessage(), $e->bizCode, $e->getCode());
}
```

> 这样：**写好的封装**负责兜底格式，**你只管抛异常/返回数据**，二者不矛盾。

---

## 3. 依赖注入与门面

### 3.1 容器解析

```php
resolve(\App\Services\UserService::class)->list();   // 走 DI 容器，自动注入依赖
app()->make(X::class);
```

### 3.2 全局助手（门面风格）

框架提供即用助手，底层是容器单例：

| 助手 | 作用 |
| --- | --- |
| `cache()` | 缓存（file/redis，见 `config/cache.php`） |
| `db()` | 数据库（`kode/database`） |
| `event()` | 事件分发 |
| `validator()` | Symfony Validator |
| `breaker()` | 熔断器管理器 |
| `lang()` / `translator()` | 国际化 |
| `config()` | 读配置 |
| `env()` | 读环境变量 |
| `ctx()` | 协程/请求上下文 |
| `runtime()` | 统一运行时（fork 隔离任务） |

示例：

```php
cache()->set('k', $v, 60);
$rows = db()->query('SELECT * FROM users WHERE id = ?', [$id]);
event(new \App\Events\UserCreated($user));
```

---

## 4. 中间件进阶

### 4.1 自定义全局中间件

实现 `MiddlewareInterface`（或 `__invoke($req, $next)`），在 `HttpServiceProvider` 用 `$app->use(new MyMiddleware())` 挂到全局链（请求总会经过）。顺序建议：RequestId → Cors → Security → Locale → [路由级 Auth/RateLimit] → 控制器。

### 4.2 自定义路由级中间件

实现同上，在路由/分组里 `.middleware(new X())` 或 `group(prefix, cb, [new X()])`。

---

## 5. 配置

- 配置目录：`config/*.php`，返回数组；用 `config('app.debug')` 读取。
- 环境变量：`.env`（本地），用 `env('APP_DEBUG', false)` 读取；生产环境建议用真实环境变量，不提交 `.env`。
- 路径助手：`base_path()`、`storage_path()`、`config_path()`、`app_path()`。

---

## 6. 缓存 / 日志 / 数据库 / 事件 / AOP

- **缓存**：`cache()->get/set/delete`，驱动在 `config/cache.php`（file/redis）。
- **日志**：`logger()->info('...')`，Monolog，路径 `storage/logs/app.log`，级别看 `LOG_LEVEL`。
- **数据库**：`db()->query/insert/update`，配置见 `config/database.php`（含 SQLite/MySQL）。
- **事件**：`event(new X())` + 监听器（`kode/event`）。
- **AOP**：用 `#[Aspect]` / 切面注解做横切（日志、事务、鉴权），见 `kode/aop`。

---

## 7. JWT 鉴权

- 签发：登录成功后用 `kode/jwt` 生成 token（`config/jwt.php` 配 `JWT_SECRET`）。
- 保护接口：挂 `AuthMiddleware`，控制器里 `ctx()->get('user')` 取当前用户。
- 失败自动返回 `E401` 信封。

---

## 8. 韧性层（限流 + 熔断 + i18n）

### 8.1 限流（保护自身）

```php
$app->get('/api/search', fn() => /* ... */)
    ->middleware(new \Kode\Framework\Http\Middleware\RateLimitMiddleware());
// 超过阈值自动 429 + RateLimit 响应头
```

### 8.2 熔断（保护下游）

熔断是**运行时无关的原语**（process / fiber / http / queue 通用），用 `breaker()`：

```php
$result = breaker()->run('user-service',
    fn() => http()->get('http://user-svc/1'),          // 受保护调用
    fallback: fn(\Throwable $e) => ['cached' => true], // 熔断打开时降级
);
```

- 连续失败达阈值 → 熔断打开 → 后续请求**直接走 fallback**，不再打下游。
- 配置见 `config/resilience.php`（可按服务名覆盖阈值）。

> **限流 vs 熔断**：限流是「节流保护自己」（429）；熔断是「隔离下游故障、降级」（open 时 fallback）。语义不同，不要合并。

### 8.3 国际化（i18n）

- 语言包：`lang/{locale}/messages.php`，如 `lang/zh-CN/messages.php`：`['welcome' => '欢迎，%name%']`。
- 使用：`lang('welcome', ['name' => 'Kode'])`，或 `translator()->trans(...)`。
- 语种由 `LocaleMiddleware` 按 `Accept-Language` 自动选择，并回写 `Content-Language`。

---

## 9. 插件化（多模块 / 独立包）

大项目可拆成插件，每个插件自带路由：

```
plugins/
└── blog/
    └── routes.php     # 返回闭包，签名同 app/routes.php
```

开启自动发现（`.env`）：

```env
ROUTES_DISCOVER_PLUGINS=true
```

框架启动时会扫描 `plugins/*/routes.php`，给每条路由打来源标签 `plugin:blog`，`route:list` 也能看到。

同理，定时任务也支持插件化：`config/schedule.php` 开启 `discover_plugins` 后，扫描 `plugins/*/src/Tasks`，来源标 `plugin:<name>`。

---

## 10. 定时任务调度

与属性路由同理念——约定优于配置、零侵入自动发现。

### 10.1 声明任务

```php
// app/Tasks/CleanupTask.php
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

// 类级：调用 handle()
#[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理')]
final class CleanupTask extends Task
{
    public function handle(): void { /* ... */ }
}

// 方法级：一个类挂多条
final class MonitorTask
{
    #[Cron('0,30 * * * *', name: 'health-ping')]
    public function ping(): void { /* ... */ }

    #[Cron('0 * * * *', name: 'metrics-summary')]
    public function metrics(): void { /* ... */ }
}
```

`#[Cron]` 参数：`expression`（5 段 cron）、`name`（展示/日志）、`description`、`enabled`（临时停用）、`cluster`（集群至多一次）。

### 10.2 运行与排查

```bash
php bin/kode cron                       # 常驻调度（Ctrl+C 优雅退出，先跑完在途任务）
php bin/kode cron --run=nightly-cleanup # 手动触发一次（调试 / CI）
php bin/kode schedule:list             # 列出全部任务：NAME / 表达式 / 来源 / 模式 / TARGET
```

运行期日志示例：

```
[schedule] 调度器启动，共 3 条任务，按 Ctrl+C 停止
[schedule] ✓ nightly-cleanup（0 0 * * *）耗时 12.34ms
[schedule] ✗ health-ping 执行失败：SQLSTATE[...]
```

### 10.3 多进程 / 多机：集群至多一次

默认 `Kode::cron()` 是**按进程**隔离的：master-worker 下每个 worker 各自触发同一表达式 → 重复 N 次。需要「全集群同一调度时刻至多执行一次」：

```php
#[Cron('0 0 * * *', cluster: true)]
final class CleanupTask extends Task { /* ... */ }
```

`cluster: true` 改用 `Kode::cronCluster()`（分布式锁）。前提：先配置协调存储 `Cluster::make('redis'|'file'...)`（同机可零依赖自动择优 file 后端）。

### 10.4 配置

```php
// config/schedule.php
return [
    'paths' => [
        'app' => base_path('app/Tasks'),
        // 'admin' => base_path('modules/admin/Tasks'),  // 多应用追加 key
    ],
    'discover_plugins' => (bool) env('SCHEDULE_DISCOVER_PLUGINS', false),
];
```

### 10.5 开发期热重载（serve --watch）

`bin/kode serve --watch` 自动监听 `app` / `config` / `src` / `public` / `bin` 下的 `.php` 变化，改动后**自动重启 serve 子进程**（Ctrl+C 优雅退出，先停子进程再退出）。看门狗把真实 serve 作为子进程，父进程用 `kode/process` 的 `FileMonitor` 轮询，与底层运行时（Native/fiber/Swoole/Workerman）无关，任何环境都能用。

```bash
php bin/kode serve --watch                 # 默认监听上述目录，排除 vendor/.git/storage/runtime 等
php bin/kode serve --watch --port 8080     # 参数透传给子进程 serve（不含 --watch）
```

自定义监听目录（`config/server.php` 的 `watch.dirs`，相对项目根；不填则用默认）：

```php
'watch' => [
    'dirs'    => ['app', 'config', 'src'],
    'exclude' => ['vendor', '.git', 'storage', 'runtime', 'node_modules', '.workbuddy'],
],
```

> 生产环境请用普通 `serve`（不带 `--watch`），避免文件轮询开销。

---

## 11. 控制台命令

声明式定义（属性注解）：

```php
use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

#[AsCommand(name: 'greet', description: '打招呼', usage: 'greet {name?} {--shout:bool}')]
final class GreetCommand extends Command
{
    public function handle(): int
    {
        $name = $this->arg('name', 'world');
        $this->line("Hello, {$name}!");
        return 0;
    }
}
```

运行：

```bash
php bin/kode console greet Kode --shout
```

命令放 `app/Console/Commands/`，框架自动扫描加载。内置 `route:list` 在 `src/Console/Commands/`（框架级，与业务命令隔离）。

---

## 11. 部署

### 11.1 多进程服务

```bash
php bin/kode serve --host 0.0.0.0 --port 9527 --workers 8
```

- `--workers` 控制 worker 进程数（按 CPU 核数调整）。
- 进程模型由 `kode/process` 提供，框架已接线。

### 11.2 反向代理（Nginx 示例）

```nginx
upstream kode {
    server 127.0.0.1:9527;
}
server {
    listen 80;
    server_name api.example.com;
    location / {
        proxy_pass http://kode;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### 11.3 热重载（开发期）

`serve --watch` 会在文件变更时自动重启 worker（基于 `kode/process` 的 `HotReloader`）。

---

## 12. 测试

```bash
vendor/bin/phpunit     # 运行 tests/ 下的单元测试
```

框架自带测试覆盖：统一响应、校验、熔断、i18n、限流等。新增业务代码建议在同一目录补 `*Test.php`。

---

## 13. 常见设计问答

**Q：统一响应是封装好还是让开发者自己返回错误？**
A：两者结合——框架**默认写好信封**并自动把异常转成信封（你不用每处手写格式）；同时保留出口：直接 `return 数组` 会自动包信封，返回原始 `Response` 可完全绕过。推荐「抛异常 + 统一信封」的写法，关注点分离最干净。

**Q：缓存目录为什么是 `storage` 而不是 `runtime`？**
A：两者不冲突、无功能影响，只是约定不同：
- `storage/`（cache、logs、sessions、framework cache）是**应用持久化运行产物**，类 Laravel 约定，适合进备份/监控。
- `runtime/` 一般指**进程瞬时产物**（pid 文件、sockets、热重载临时态），`.gitignore` 已忽略 `/.runtime/`。
框架把缓存/日志放 `storage`，符合主流习惯，你可随时在 `config/cache.php`、`config/logging.php` 改路径。

**Q：路由功能（分组/嵌套/中间件/别名/直接返回）都要自己实现吗？**
A：不需要——`kode/http` 已全部提供（分组、嵌套分组、路由级中间件、命名路由/别名、参数正则、默认参数、URL 反向生成、直接返回数组/Response）。框架只做薄封装与约定，开箱即用。
