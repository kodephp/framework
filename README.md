# Kode Framework

基于 [kode](https://packagist.org/packages/kode/) 生态组件构建的**现代 PHP 全栈框架**——定位为**地基框架**：薄核 + 接线点，能力以可插拔包的形式往上叠加。最低 PHP 8.3+，企业级、工业级架构开箱即用。

设计立场：

- 以 **`kode/core` 作为启动器**（`App::boot()` 统一加载配置 → 注册 ServiceProvider → 启动运行时）；
- 以 **`kode/runtime` 作为统一运行时**（Fiber / 多进程 / 多线程 / Swoole / Swow / 分布式，业务代码不变即可切换）；
- 核心用 kode，周边复用业界最佳包（Monolog 日志、Symfony Validator 校验、Symfony Translation 国际化、PSR 标准）。

## 架构总览

```
请求 / 命令
   │
   ▼
Kode\Framework\Application          ← 薄外壳（.env、path.base、收集 providers/runtime）
   │  Kode\Core\App::boot()          ← kode/core 启动器（配置 + Provider + 运行时）
   ▼
┌──────────────────────────────────────────────────────────────────┐
│  kode/core 内核：Config / Container(kode/di) / Facade / Runtime / Context
└──────────────────────────────────────────────────────────────────┘
   │
   ├─ HTTP  → Kode\Http\App        （kode/http + kode/router + PSR-7）
   ├─ CLI   → Kode\Console\Kernel  （kode/console）
   └─ 运行时 → runtime()           （kode/runtime 桥接 process/fibers/parallel/分布式）
```

**地基范围**

| 层               | 内容                                                                     | 决策                      |
| --------------- | ---------------------------------------------------------------------- | ----------------------- |
| 薄核              | Application 启动、DI 容器、Config、Facade、Runtime、路由、统一信封、异常体系、全局中间件链、健康检查、日志 | 必须有                     |
| 韧性层             | 限流（`kode/limiting`）+ 熔断（框架中性 `InMemoryBreaker`，运行时无关）                  | 必须有，合并为 `Resilience` 概念 |
| 国际化             | `symfony/translation` 薄封装                                              | 必须有                     |
| OPT-IN 接线（生态已有） | 定时任务、热重载、分布式限流、WebSocket、自定义进程、Snowflake、连接池                           | `config` 启用，默认不加载       |
| 后续包（按需）         | 配置中心、OpenAPI、视图                                                        | 往上加包，不进地基               |

## 特性

- **多进程 HTTP 服务**：`kode/process` master-worker 预派生（零扩展常驻内存，不锁 Swoole/Workerman）。
- **统一运行时**：fiber（默认协程）/ process / parallel（ZTS 多线程）/ distributed 一套业务代码通吃。
- **统一响应信封** `{code, msg, data}`：所有响应（含 401/404/422/429/500）走 `Resp`，无手写 JSON、`->send()`。
- **全局中间件链**（默认开启，配置可关）：`RequestId → Cors → Security → [路由级 Auth/RateLimit]`。
- **韧性层**：限流（保护自身）+ 熔断（保护下游，运行时无关）。
- **国际化**：`symfony/translation` + `lang()` + `Accept-Language` 中间件。
- **契约化可替换**：AuthGuard 等契约解耦，换方案只改绑定。
- **AOP**：`kode/aop` 原生 Attribute 切面。
- **成熟周边**：Monolog 日志、Symfony Validator 校验、`kode/cache`/`event`/`queue`/`database`/`http-client`/`messaging`。

## 企业级默认能力

### 全局中间件链

```
客户端 → [RequestId] → [Cors] → [Security] → [路由级 Auth/RateLimit] → 控制器
```

- `RequestIdMiddleware`：分配/透传 `X-Request-Id`（UUID v4），回写响应头，便于链路追踪。
- `CorsMiddleware`：`Access-Control-*`；`OPTIONS` 预检直接 204；`allowed_origins` 支持 `*`/单域名/数组白名单，凭证模式禁通配。
- `SecurityHeadersMiddleware`：`X-Content-Type-Options` / `X-Frame-Options` / `Referrer-Policy` / `HSTS`。

### 健康检查 / 探针

```bash
curl http://127.0.0.1:9527/health
# {"code":0,"msg":"healthy","data":{"status":"ok","service":"kode-app","version":"1.0.0","php":"8.3.33","env":"local","time":"..."}}
curl http://127.0.0.1:9527/ping
# {"code":0,"msg":"pong","data":{"pong":true}}
```

### 统一异常处理（安全兜底）

- `ValidationException` → `422`（`errors` 进 `data`）；其它异常 → `500`，生产环境不泄露内部信息与堆栈，仅记日志；404 → `E404` 信封。

## 韧性层：限流 vs 熔断

限流与熔断是不同维度的稳定性保护，**语义不混、逻辑不合并**，但对外提供统一门面：

|      | 限流 Rate Limiter | 熔断 Circuit Breaker                                             |
| ---- | --------------- | -------------------------------------------------------------- |
| 保护目标 | 保护**自身**不被流量打垮  | 保护**下游依赖**不因故障级联雪崩                                             |
| 触发   | 请求数/频率超阈值       | 下游错误率超阈值                                                       |
| 行为   | 拒绝（429）         | 打开后快速失败 + 降级 `fallback`，半开探活                                   |
| 实现   | `kode/limiting` | 框架中性 `CircuitBreaker` 契约 + `InMemoryBreaker`（默认引擎，可替换 Redis 等） |

```php
// 限流：返回 LimiterResult（allowed/remaining）
rateLimit()->consume('user:123', 1);

// 熔断：保护下游调用，失败时降级
$user = breaker()->run('user-service',
    fn () => http()->get('http://user-svc/1'),
    fallback: fn () => Resp::fail('服务暂不可用', 'E503', 503),
);
```

**熔断跨运行时、不限制于 fibers**：框架自带 `Resilience\CircuitBreaker` 契约与默认引擎 `InMemoryBreaker` 均为**纯 PHP 状态机**（仅用 `microtime()` + 类属性，无 Fiber/协程/事件循环依赖），因此 process worker、fiber 任务、普通 HTTP handler、queue consumer 等**任意运行时通用**，从根上不绑定 `kode/fibers`。`Resilience\Breaker` 仅依赖 `CircuitBreaker` 契约，具体引擎通过可注入工厂提供（默认即 `InMemoryBreaker`），将来要换 Redis 分布式熔断只需替换工厂。

> 跨请求共享限流/熔断状态需用共享存储（如 Redis）；内存存储仅在单请求/单 worker 内有效。

## 国际化（i18n）

复用 `symfony/translation`（框架只做薄封装），支持应用级 `lang/<locale>/messages.php`，占位符遵循 `%name%`：

```php
lang('welcome', ['name' => 'Kode']);        // 当前语种文案
translator()->getLocale();                  // 当前语种
translator()->setLocale('en');              // 手动切换
```

`LocaleMiddleware`（`config/locale.php`，默认开启）解析 `Accept-Language` 自动选语种，并回写 `Content-Language` 响应头。语言资源目录优先级：应用 `lang/` → 框架内置（应用可覆盖）。

## 安装

本框架以 **Composer 包**发布（不是「下载全仓在 app/ 里写业务」）：

```bash
composer require kode/framework
vendor/bin/kode new myapp          # 一键生成 app/ config/ public/ bin/ lang/ + 项目 composer.json
cd myapp
composer install
cp .env.example .env
php bin/kode serve                 # 多进程 HTTP 服务（默认 127.0.0.1:9527）
```

`kode new` 从框架自带骨架复制目录，并写入仅依赖 `kode/framework` 的 `composer.json`；业务代码只写在生成的 `app/` 里，框架包保持精简、可独立升级。

## 目录结构

```
kode/framework/                 # 框架包（composer require 的对象）
├── src/                        # 框架核心（Kode\Framework\*），发布内容
│   ├── Application.php         # 薄外壳（委托 Kode\Core\App::boot()）
│   ├── Console/Command.php     # 控制台命令基类（arg/flag/info...）
│   ├── Facades/                # 门面（继承 Kode\Core\Facade）
│   ├── Providers/              # ServiceProvider 基类 + 内置 Provider
│   ├── Http/                   # 控制器基类 / Resp 统一响应 / 中间件
│   ├── Resilience/             # 熔断：CircuitBreaker(契约) / InMemoryBreaker(默认引擎) / Breaker(管理器)
│   ├── Translation/Translator.php # symfony/translation 薄封装
│   ├── Validation/             # Symfony Validator 封装
│   └── Support/helpers.php      # 全局助手（resolve/event/logger/jwt/breaker/lang...）
├── bin/kode                    # new（脚手架）/ serve（多进程）/ console（透传）
├── config/                     # 框架默认配置（随 kode new 复制）
├── lang/                       # 框架内置语言包（模板）
└── composer.json               # 依赖 + bin + autoload

# ↓↓↓ 以下由 vendor/bin/kode new myapp 生成（业务只写这里）↓↓↓
myapp/
├── app/{Http/Controllers, Console/Commands, Services, Aop, Events, bootstrap.php, routes.php}
├── config/  lang/  public/  bin/  storage/  tests/
└── composer.json               # 只依赖 kode/framework
```

## 运行

### HTTP 服务（多进程，默认）

```bash
php bin/kode serve                       # 默认 http://127.0.0.1:9527，worker=CPU 核数
php bin/kode serve --port 8080 --workers 8
php bin/kode serve --watch               # 开发期热重载：监听 app/config/src/public/bin 的 .php 变化，自动重启
```

```bash
curl http://127.0.0.1:9527/health              # 健康检查
curl http://127.0.0.1:9527/users/42             # 路由参数
curl -X POST -H "Content-Type: application/json" \
     -d '{"name":"Tom","email":"tom@example.com","age":20}' \
     http://127.0.0.1:9527/users                 # 校验失败 → 422

TOKEN=$(curl -s -X POST -H "Content-Type: application/json" \
     -d '{"username":"alice","password":"secret123"}' \
     http://127.0.0.1:9527/auth/login | php -r '$d=json_decode(file_get_contents("php://stdin"),true);echo $d["data"]["token"];')
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:9527/me   # 受保护接口
```

### 控制台

```bash
php bin/console                 # 命令列表
php bin/console greet Kode --shout
```

## 使用指南

### 配置

`config('key')` / `config('key.sub')` 读嵌套配置（`kode/core` 按点号遍历）：

```php
config('jwt.guards.api.ttl');
config('app.debug', false);
```

`config/app.php` 可声明 `providers`（额外 Provider）与 `runtime`（运行时数组，默认 `['fiber']`）。`.env` 用 `env('KEY', 'default')` 读取。

### 门面与助手

| 助手                                                         | 说明               |
| ---------------------------------------------------------- | ---------------- |
| `app()` `config($k,$d)` `runtime()` `ctx()` `resolve($id)` | 核心助手（kode/core）  |
| `event($e)` / `logger()` / `cache()`                       | 事件 / 日志 / 缓存     |
| `validator()->validate($data, $rules)`                     | 参数校验             |
| `jwt()->issue($claims)` / `jwt()->authenticate($token)`    | JWT              |
| `rateLimit()->consume($key, $n)`                           | 限流               |
| `breaker()->run($name, $task, $fallback)`                  | 熔断               |
| `http()->get($url)` / `http()->post($url, $body)`          | HTTP 客户端（PSR-18） |
| `messaging()->bus('memory')->publish($ch, $data)`          | 消息总线             |
| `lang($key, $params)` / `translator()`                     | 国际化              |

### 控制器

继承 `Kode\Framework\Http\Controller`，直接 `return` 数组（自动 JSON 化）或 `Response`：

```php
namespace App\Http\Controllers;

use Kode\Http\Request;
use Kode\Framework\Http\Controller;

final class UserController extends Controller
{
    public function show($req)
    {
        $id = Request::param('id');
        $page = Request::integer('page', 1);
        return ['id' => $id, 'page' => $page];
    }

    public function store($req)
    {
        $data = $this->validate(Request::all(), [
            'name'  => 'required|min:2|max:50',
            'email' => 'required|email',
        ]);
        return $this->ok($data, '创建成功');   // 统一信封 code=0
    }
}
```

**请求取值（短写法）**：`$this->request()` 仍返回完整 PSR-7 请求（header / 上传文件 / body stream）；日常取参优先用以下短方法（底层复用 `kode/http` 的 `Request` 助手，已含 query+body+json 合并解析）：

```php
$this->input('name');            // 合并取值（query+body+json），缺省返回 null
$this->input(['name', 'page']);  // 数组键 → 仅返回这些字段（等价 only()）
$this->query('fail');            // 仅 GET 查询参数（?fail=1）
$this->post('payload');          // 仅请求体（含 json 解析）
$this->params();                 // 全部入参（合并）数组
$this->only('name', 'page');     // 字段筛选
```

等价全局写法（服务 / 中间件里也能用）：`Request::input('name')`、`Request::get('fail')`、`Request::all()`。

### 统一响应（code/msg/data）

```jsonc
{ "code": 0, "msg": "创建成功", "data": { "id": 1 } }          // 成功
{ "code": "E400", "msg": "参数错误" }                            // 失败
{ "code": "E422", "msg": "参数校验失败", "data": { "errors": [] } } // 校验失败
```



```php
return $this->ok($user, '创建成功');
return $this->fail('参数错误', 'E400', 400);
return $this->paginate($items, $total, $page, $size);
```

### 路由

`app/routes.php` 返回闭包，接收 `Kode\Http\App` 实例：

```php
use Kode\Http\App;
use Kode\Framework\Http\Middleware\AuthMiddleware;

return function (App $app): void {
    $app->get('/users/{id:\d+}', fn($req) => resolve(\App\Http\Controllers\UserController::class)->show($req))
        ->name('user.show');
    $app->get('/me', fn($req) => resolve(\App\Http\Controllers\UserController::class)->me($req))
        ->middleware(new AuthMiddleware());
};
```

也可使用**属性路由**（约定优于配置，自动发现，无需手写 routes.php）：

```php
use Kode\Framework\Http\Attributes\Controller as RouteController;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Controller;

#[RouteController(prefix: '/products')]
final class ProductsController extends Controller
{
    #[Get('')]                                   // GET /products
    public function index() { /* ... */ }

    #[Get('/{id:\d+}', name: 'product.show')]     // 命名路由 + 路由参数
    public function show() {
        $id = (int) $this->param('id');           // 路由参数用 $this->param()
        /* ... */
    }
}
```

两种模型并存：属性路由先注册、routes.php 显式条目可覆盖同名路径。`route:list` 都会列出。

中间件返回**真实 PSR-7 响应**（由框架统一发出，**不要手动 `->send()`**）：`return Resp::fail('未授权', 'E401', 401);`

### 熔断 / 限流 / 国际化（演示接口）

```bash
curl http://127.0.0.1:9527/demo/breaker          # 正常：state=closed
curl "http://127.0.0.1:9527/demo/breaker?fail=1" # 连续失败 → 熔断 → 降级（trace 可见过程）
curl http://127.0.0.1:9527/demo/ratelimit        # 限流消耗
curl -H "Accept-Language: en" http://127.0.0.1:9527/demo/i18n  # 自动切英文，回写 Content-Language
```

### 注解速查（PHP 8 Attributes）

| 注解                                                                                                                              | 包                 | 用途             |
| ------------------------------------------------------------------------------------------------------------------------------- | ----------------- | -------------- |
| `#[AsCommand(name, description, usage)]`                                                                                        | kode/console      | 控制台命令声明        |
| `#[Aspect]` / `#[Before]` / `#[After]` / `#[Around]` / `#[AfterReturning]` / `#[AfterThrowing]` / `#[Pointcut]` / `#[Priority]` | kode/aop          | AOP 切面         |
| `#[Assert\*]`（`NotBlank` / `Email` / `Length` …）                                                                                | symfony/validator | 参数校验           |
| `kode/attributes` `Attr` 门面                                                                                                     | kode/attributes   | 反射读取任意目标的自定义属性 |

### 控制台命令

继承 `Kode\Framework\Console\Command`，用 `#[AsCommand]` 声明，无需写 `argument()`/`writeln()`：

```php
use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

#[AsCommand(name: 'greet', description: '打招呼', usage: 'greet {name?} {--shout:bool}')]
final class GreetCommand extends Command
{
    protected function handle(): int
    {
        $name = $this->arg('name') ?? 'World';
        $this->info($this->flag('shout') ? strtoupper("Hello, {$name}!") : "Hello, {$name}!");
        return 0;
    }
}
```

内置快捷方法：`arg()` / `flag()` / `opt()` / `info()` / `line()` / `warn()` / `error()` / `success()` / `table()`。

**框架内置命令**

- `route:list`：列出全部路由，按分组（URI 段）聚合并显示数量，含中间件 / 来源 / 处理器；支持大项目折叠查看。

  ```bash
  php bin/kode console route:list              # 全量，按分组展示
  php bin/kode console route:list --compact     # 仅看「分组 → 数量」摘要
  php bin/kode console route:list --group=demo  # 只看某分组
  php bin/kode console route:list --method=POST # 按 HTTP 方法过滤
  php bin/kode console route:list --source=app  # 按来源过滤（app / plugin:<name>）
  ```

  来源标签由 `config/routes.php` 驱动：默认 `app` 为 `app/routes.php`；可声明 `sources` 额外路由文件，或开启 `discover_plugins` 自动发现 `plugins/<name>/routes.php`（标签 `plugin:<name>`）。

### 定时任务调度（约定优于配置）

用 `#[Cron('分 时 日 月 周')]` 声明定时任务，无需逐条登记；`bin/kode cron` 自动发现并常驻调度（基于 kode/process 定时器）。支持类级（调用 `handle()`）与方法级（一个类挂多条）。

```php
// app/Tasks/CleanupTask.php
#[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理')]
final class CleanupTask extends Task   // 可选继承 Task，获得类型明确的 handle()
{
    public function handle(): void
    {
        // 业务逻辑；构造依赖由容器自动注入。
    }
}

// 方法级：一个类挂多条
#[Cron('0,30 * * * *', name: 'health-ping')]
public function ping(): void { /* ... */ }
```

命令：

```bash
php bin/kode cron                # 常驻运行所有 #[Cron] 任务（Ctrl+C 优雅退出）
php bin/kode cron --run=nightly-cleanup   # 立即手动触发某条任务一次（调试/CI）
php bin/kode schedule:list       # 列出所有已发现任务（含表达式/来源/是否集群）
```

- 配置见 `config/schedule.php`：`paths`（任务目录，多应用追加 key 即可）、`discover_plugins`（开启后扫描 `plugins/<name>/src/Tasks`，来源标 `plugin:<name>`）。
- 多进程/多机需「同一调度时刻至多执行一次」：任务上 `#[Cron(cluster: true)]` 即用分布式锁（`Kode::cronCluster`），前提是已通过 `Cluster::make('redis'|'file'...)` 配置协调存储。
- 临时停用某任务：`#[Cron(..., enabled: false)]`，无需删代码。

## 现代化 PHP 8.3 约定

框架最低 PHP 8.3，内置组件采用 8.3+ 写法：`#[\Override]`、`readonly`、DNF 类型、类型化类常量、`json_validate()`、`enum` + 枚举方法、一等公民可调用 `strlen(...)` 等。

## 许可证

**MIT**（与 `kode/core` 及 Monolog / Symfony / Nyholm PSR-7 一致，且兼容 `kode/jwt` 的 Apache-2.0）。`LICENSE` 末尾附有 `kode/jwt` 的第三方许可说明，重新分发时请一并保留。

MIT
