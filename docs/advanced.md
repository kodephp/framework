# 进阶用法

本篇按「能直接抄去用」的方式，逐个讲清楚框架的每一项能力。每个小节都可独立查阅。

- 路由全解
- 请求对象（取值 / Header / Cookie / 文件 / JSON）
- 响应对象（JSON / 错误 / 重定向 / 文件 / 状态码与头）
- 自己写中间件
- 参数校验
- 异常处理
- 配置与环境变量
- 日志
- 鉴权（JWT）
- 限流
- 熔断（保护下游）
- 定时任务
- 多进程 HTTP 服务
- 缓存 / 队列 / 数据库 / 事件 / HTTP 客户端 / 消息
- 门面与全局助手
- 控制台命令
- DI 与服务提供者
- AOP 切面
- 插件
- 部署到生产
- 测试

---

## 1. 路由全解

框架支持两套并存模型，**默认即自动发现，无需开关**。

### 1.1 闭包路由（`app/routes.php`）

```php
use Kode\Http\App;

return function (App $app): void {
    $app->get('/', fn() => ['ok' => true]);
    $app->post('/users', fn() => /* ... */);
    $app->any('/ping', fn() => 'pong');          // 任意方法
    $app->add(['GET','POST'], '/both', fn() => 'x');
};
```

`app/routes.php` 是入口文件；此外框架会**自动 glob `app/routes/*.php`**（每个文件即一个来源，新增文件即生效，无需登记）。

### 1.2 属性路由（约定优于配置）

在控制器类 / 方法上用属性声明，启动时自动扫描 `app/Http/Controllers`（递归子目录，新建子文件夹即一个模块）：

```php
use Kode\Framework\Http\Attributes\Controller as RouteController;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;
use Kode\Framework\Http\Controller;

#[RouteController(prefix: '/products')]
final class ProductsController extends Controller
{
    #[Get('')]                                   // GET /products
    public function index() { /* ... */ }

    #[Get('/{id:\d+}', name: 'product.show')]     // 命名路由 + 路由参数
    public function show() {
        $id = (int) $this->param('id');           // 路径参数用 $this->param()
    }

    #[Post('')]
    public function store() { /* ... */ }
}
```

可用属性：`#[Controller(prefix, middleware)]`、`#[Route(methods, path, name, middleware)]`，以及语法糖 `#[Get]` `#[Post]` `#[Put]` `#[Delete]` `#[Patch]` `#[Any]`。路径参数用 `{id}` 匹配、`{id:\d+}` 加正则、`{name}?` 可选。

### 1.3 路由参数与分组

```php
// 路由分组（前缀 + 中间件）
$app->group('/api/v1', function (App $app): void {
    $app->group('/users', function (App $app): void {
        $app->get('/{id}', fn() => /* ... */);
    });
}, [new \App\Http\Middleware\ApiGuard()]);
```

### 1.4 路由级中间件（单条）

```php
$app->get('/me', fn() => resolve(UserController::class)->me())
    ->middleware(new \Kode\Framework\Http\Middleware\AuthMiddleware());
```

属性路由也能在类 / 方法上挂中间件：`#[Controller(prefix: '/x', middleware: [X::class])]`、方法级 `#[Route(..., middleware: [Y::class])]`。

### 1.5 路由来源与 `route:list`

属性路由标签为 `app`，插件路由为 `plugin:<name>`；`route:list` 两种都会列出：

```bash
php bin/kode console route:list                 # 全量，按 URI 段分组
php bin/kode console route:list --compact       # 仅看「分组 → 数量」
php bin/kode console route:list --group=api     # 只看某分组
php bin/kode console route:list --method=POST
php bin/kode console route:list --source=app
php bin/kode console route:list --rate-limit    # 额外显示每条路由的 #[RateLimit]
php bin/kode console route:list --columns=method,uri,name
```

### 1.6 生成 URL

给路由起名后用 `route()` 反向生成：

```php
#[Get('/products/{id}', name: 'product.show')]
// route('product.show', ['id' => 1])  =>  /products/1
```

---

## 2. 请求对象

### 2.1 控制器短方法（日常首选）

```php
$this->input('name');          // GET + POST + JSON 合并；缺省 null
$this->input(['name','page']); // 只要这几个字段
$this->query('page');          // 仅 ?page=2
$this->post('payload');        // 仅请求体（含 JSON）
$this->params();               // 全部入参
$this->only('name','page');    // 字段筛选
$this->param('id');            // 路由路径参数
```

等价全局写法（服务 / 中间件里也能用）：`Request::input('name')`、`Request::get('fail')`、`Request::all()`。

### 2.2 完整 PSR-7 请求

需要 header / Cookie / 上传文件 / body 流时，用 `$req = $this->request()`：

```php
$token = $req->getHeaderLine('Authorization');
$ip    = $req->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
$ctype = $req->getContentType();
foreach ($req->getUploadedFiles() as $file) {
    $file->moveTo(storage_path('uploads/' . $file->getClientFilename()));
}
```

---

## 3. 响应对象

| 写法 | 效果 |
| --- | --- |
| `return ['k'=>'v'];` | 标准 JSON（默认） |
| `return $this->json($data);` | 标准成功 JSON |
| `return $this->error('出错了', 400);` | 标准错误，HTTP 400 + `{"message":"出错了"}` |
| `return $this->error('校验失败', 422, ['errors' => $e->errors()]);` | 带额外字段 |
| `return $this->response($data)->status(201)->header('X-A', '1');` | 链式自定义状态码 / 头 |
| `return Resp::json($data);` / `Resp::error($msg, 400);` | 全局助手（任何位置可用） |
| `return Resp::redirect('https://example.com');` | 302 重定向 |
| `return Resp::noContent();` | 204 |
| `return Response::make($rawBody, 200, ['Content-Type' => 'application/json']);` | 想完全绕过框架封装 |

> 想返回文件流 / 裸 JSON？直接返回 `Kode\Http\Response` 即可。

---

## 4. 自己写中间件

中间件实现 PSR-15 的 `MiddlewareInterface`，包一层逻辑后调用 `$handler->handle($request)`：

```php
namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ApiGuard implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('X-Api-Key') !== 'secret') {
            return Resp::error('禁止访问', 403);
        }
        return $handler->handle($request);   // 放行
    }
}
```

用法：路由级 `$app->get('/x', fn()=>...)->middleware(new ApiGuard())`，或属性路由 `#[Route(..., middleware: [ApiGuard::class])]`，或分组 `$app->group('/api', fn()=>..., [new ApiGuard()])`。

框架已内置：`AuthMiddleware`（JWT 鉴权）、`RateLimitMiddleware`（限流）、`CorsMiddleware` / `SecurityHeaders` / `RequestId`（来自 `kode/http`，由 `HttpServiceProvider` 按 config 开关装配）。

---

## 5. 参数校验

两种写法：

**A. 控制器里快捷校验**（失败自动转 422）：

```php
$data = $this->validate($this->params(), [
    'name'  => 'required|min:2|max:50',
    'email' => 'required|email',
    'age'   => 'int|min:0',
]);
```

**B. 用 Symfony Attribute 声明在 DTO 上**（推荐复杂表单）：

```php
use Symfony\Component\Validator\Constraints as Assert;

final class CreateUser
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    public string $name;

    #[Assert\Email]
    public string $email;
}
```

规则串参考：`required` `email` `int` `numeric` `min:2` `max:50` `in:a,b` 等。

---

## 6. 异常处理

- 全局 `ExceptionMiddleware` 接管所有未捕获异常，转成结构化 JSON（见入门指南 §7）。
- 业务里直接抛异常即可：框架负责格式，你只管抛。

```php
if ($user === null) {
    throw new \RuntimeException('用户不存在');   // → 500 {"message":"用户不存在", ...}
}
```

- 校验异常 → 422（含 `errors`）；其它 → 500；404 / 405 / 401 / 429 自动映射。
- 想拿异常管理器：`exception_manager()` 助手或 `Exception` 门面。

---

## 7. 配置与环境变量

读配置：`config('jwt.guards.api.ttl')`、`config('app.debug', false)`（点号访问嵌套）。

写配置：`config/*.php` 返回数组；`.env` 用 `env('KEY', 'default')` 读取。

```dotenv
# .env
APP_DEBUG=true
APP_NAME=kode-app
JWT_SECRET=change-me
RATE_LIMIT_DRIVER=memory
```

配置期 `app()` 可能尚未就绪，路径类配置请写「相对项目根」的子路径（框架会用真实 `path.base` 拼成绝对路径），不要手写 `base_path()`。

---

## 8. 日志

基于 Monolog：

```php
logger()->info('用户登录', ['uid' => 1]);
logger()->error('下游失败', ['e' => $e->getMessage()]);
Log::warning('...');          // 门面写法
```

日志文件默认在 `storage/logs/app.log`；级别 / 通道在 `config/logging.php` 配置。

---

## 9. 鉴权（JWT）

### 9.1 签发令牌（登录接口示例）

```php
public function login(): array
{
    $data = $this->validate($this->params(), [
        'username' => 'required',
        'password' => 'required',
    ]);

    // 真实项目在此校验密码；这里仅演示签发
    $token = jwt()->issue([
        'uid'   => 1,
        'sub'   => 'u1',
        'roles' => ['user'],
    ]);

    return $this->json(['token' => $token]);
}
```

`jwt()->issue($claims)` 委托 `kode/jwt` 守卫签发；每次签发都是独立实例，jti 唯一、不会泄漏前次声明。

### 9.2 保护路由

```php
$app->get('/me', fn() => resolve(UserController::class)->me())
    ->middleware(new \Kode\Framework\Http\Middleware\AuthMiddleware());
```

`AuthMiddleware` 从 `Authorization: Bearer <token>` 解析，失败返回 401，成功把载荷挂到请求属性 `auth`。

### 9.3 在控制器里取当前用户

```php
public function me(): array
{
    /** @var \Kode\Jwt\Token\Payload $auth */
    $auth = $this->request()->getAttribute('auth');
    return ['uid' => (string) $auth->uid];
}
```

也可手动校验：`$payload = jwt()->authenticate($token);`，注销：`jwt()->invalidate($token)`。

---

## 10. 限流

全局 `RateLimitMiddleware` 默认开启。

- **声明式**：控制器类 / 方法上 `#[RateLimit]`（类级对全部方法生效，方法级叠加，可多条）。
- **全局默认**：未声明 `#[RateLimit]` 的路由，按 `config/limiting.php` 的 `capacity/rate/algorithm` 限流，维度为「路由模板 + 客户端 IP」。
- **分布式**：`config/limiting.php` 的 `driver` 设为 `redis` 即跨进程 / 跨机共享限额。

```php
use Kode\Limiting\Attribute\RateLimit;

#[Controller(prefix: '/products')]
#[RateLimit(capacity: 100, rate: 5.0, key: 'products:{ip}')]   // 类级
final class ProductsController extends Controller
{
    #[Get('')]
    #[RateLimit(capacity: 20, rate: 1.0, key: 'products:list:{ip}')] // 方法级叠加
    public function index() { /* ... */ }
}
```

超限返回 **429** + 标准头（`X-RateLimit-Limit` / `Remaining` / `Reset`、`Retry-After`）。代码中手动限流：`rateLimit()->consume('op:'.$ip, 1)`。

---

## 11. 熔断（保护下游）

限流保护「自身」，熔断保护「下游」——下游错误率过高时快速失败并降级，避免级联雪崩。

```php
$user = breaker()->run(
    'user-service',
    fn () => http()->get('http://user-svc/1'),
    fallback: fn () => Resp::error('用户服务暂不可用', 503),
);
```

`breaker()` 是纯 PHP 状态机，跨运行时通用（多进程 worker / Fiber / 普通 handler / 队列消费都可用）。跨请求共享状态需接 Redis 等共享存储。

---

## 12. 定时任务

用 `#[Cron('分 时 日 月 周')]` 声明，无需登记；`bin/kode cron` 自动发现并常驻调度（基于 `kode/process` 定时器）：

```php
use Kode\Framework\Scheduling\Attributes\Cron;
use Kode\Framework\Scheduling\Task;

#[Cron('0 0 * * *', name: 'nightly-cleanup', description: '每天 0 点清理')]
final class CleanupTask extends Task
{
    public function handle(): void
    {
        // 业务逻辑；构造依赖由容器自动注入
    }
}
```

```bash
php bin/kode cron                      # 常驻运行所有 #[Cron] 任务（Ctrl+C 优雅退出）
php bin/kode cron --run=nightly-cleanup  # 立即手动触发一次（调试 / CI）
php bin/kode schedule:list             # 列出所有已发现任务
```

- `--run=<name>` 手动触发；`#[Cron(..., enabled: false)]` 临时停用；`#[Cron(cluster: true)]` 走分布式锁（需先配置协调存储）。
- 多应用：在 `config/schedule.php` 的 `paths` 追加更多目录 key。

---

## 13. 多进程 HTTP 服务

```bash
php bin/kode serve                          # 默认 http://127.0.0.1:9527，worker=CPU 核数
php bin/kode serve --port 8080 --workers 8
php bin/kode serve --watch                  # 开发期热重载（监听 .php 变化自动重启）
```

每个 worker 独立重建应用，数据库连接 / 缓存句柄 / JWT 密钥等可变状态按进程隔离。`--watch` 仅用于开发，生产不要用。

---

## 14. 生态能力（缓存 / 队列 / 数据库 / 事件 / HTTP 客户端 / 消息 / 国际化 / 跨域）

这些能力由对应的 kode 生态包提供，框架只做薄封装与接线。统一通过门面或全局助手使用。
完整 API 与配置项见各 `config/*.php` 与对应 kode 包的文档。

### 14.1 缓存（kode/cache，PSR-16）

```php
// 助手
cache()->set('user:1', $profile, 3600);     // 写，TTL 秒
$profile = cache()->get('user:1');          // 读，缺失返回 null
cache()->forget('user:1');                  // 删

// 一次计算并缓存（回调结果缓存到命中）
$hot = cache()->remember('hot_posts', 60, fn () => Post::topN(10));

// 门面等价写法
Cache::put('k', $v, 60);
$v = Cache::get('k');
```

driver 见 `config/cache.php`（默认 redis，可切 file/memory）。

### 14.2 队列（kode/queue，内建 Worker）

```php
// 定义一个 job（普通类即可，消费时按构造参数反序列化）
final class SendMail
{
    public function __construct(public array $data) {}
    public function handle(): void { /* 调邮件服务发信 */ }
}

// 投递：推「类名 + 数据」，或推实例
Queue::push(SendMail::class, ['to' => 'a@b.com']);
Queue::push(new SendMail(['to' => 'a@b.com']));
Queue::later(5, SendMail::class, ['to' => 'a@b.com']);   // 5 秒后

// 助手等价
queue()->push(SendMail::class, ['to' => 'a@b.com']);
```

driver 见 `config/queue.php`（redis/database/beanstalkd/amqp/kafka）。消费由 kode/queue 内建
Worker 完成（启动方式见该包文档 / `config/queue.php` 注释）。

### 14.3 数据库（kode/database）

```php
// 查询构造器（推荐，自动防注入）
$user = DB::table('users')->where('id', 1)->first();          // 单行
$rows = DB::table('users')->where('age', '>', 18)->get();     // 集合
$page = DB::table('posts')->paginate(15);                     // 分页

DB::table('users')->insert(['name' => 'Kode', 'age' => 20]);
DB::table('users')->where('id', 1)->update(['age' => 21]);
DB::table('users')->where('id', 1)->delete();

// 原生 SQL（参数绑定，绝不拼接）
$rows = db()->select('SELECT * FROM users WHERE id = ?', [1]);

// 事务
DB::transaction(function () {
    DB::table('accounts')->where('id', 1)->decrement('balance', 100);
    DB::table('accounts')->where('id', 2)->increment('balance', 100);
});
```

连接配置见 `config/database.php`。

### 14.4 事件（kode/event，PSR-14）

```php
// 监听
Event::listen(\App\Events\UserRegistered::class, function (\App\Events\UserRegistered $e) {
    // 发欢迎邮件、打点……
});

// 触发
event(new \App\Events\UserRegistered($uid));
```

### 14.5 HTTP 客户端（kode/http-client，PSR-18）

```php
$resp = Http::get('http://user-svc/1');        // 助手 http() 等价
$code = $resp->getStatusCode();
$body = $resp->getBody()->getContents();
$json = json_decode($body, true);

$resp = Http::post('http://user-svc', ['json' => ['name' => 'Kode']]);
```

### 14.6 消息总线（kode/messaging）

```php
// 发布到主题
Messaging::publish('order.created', ['id' => 123]);

// 订阅（通常在常驻消费进程中）
messaging()->subscribe('order.created', function (array $payload) {
    // 处理订单
});

// 指定总线（默认 memory，可切 redis/nats/mqtt…）
messaging()->bus('redis')->publish('order.created', $payload);
```

### 14.7 国际化（多语言）

框架内置翻译能力（底层复用 symfony/translation），按语种从 `lang/<locale>/messages.php` 加载文案。

```php
// 在代码里取文案
echo lang('welcome', ['name' => 'Kode']);      // 用当前语种渲染
echo translator()->trans('user_not_found', ['id' => 42]);

// 手动切语种（程序化）
Translator::setLocale('en');
```

`lang/zh-CN/messages.php` 示例：

```php
<?php return [
    'welcome'        => '欢迎，%name%',
    'user_not_found' => '用户 %id% 不存在',
];
```

`lang/en/messages.php` 示例：

```php
<?php return [
    'welcome'        => 'Welcome, %name%',
    'user_not_found' => 'User %id% not found',
];
```

**按请求自动选语种**：开启 `LocaleMiddleware`（默认开，`config/locale.php` 的 `enabled`）后，
框架读取请求头 `Accept-Language` 自动切换语种，并在响应写 `Content-Language`。语种存于请求上下文
（kode/context，按 fiber/协程隔离），并发请求互不污染。可用语种在 `config/locale.php` 的
`available` 中配置，默认语种在 `default`。

### 14.8 跨域 CORS 与安全响应头

默认开启 CORS 与安全头（见 `config/cors.php`、`config/security.php`）：

- `config/cors.php`：允许的 origins / methods / headers / 是否带凭证。
- `config/security.php`：默认注入 `X-Content-Type-Options`、`X-Frame-Options`、
  `Content-Security-Policy` 等（开关 `enabled`）。

需要自定义响应头时，在控制器里照常 `withHeader('X-Custom', $v)` 即可，安全头会在管线末端补上。





---

## 15. 门面与全局助手

| 助手 | 说明 |
| --- | --- |
| `app()` `config($k,$d)` `ctx()` `resolve($id)` | 核心（kode/core） |
| `base_path()` `storage_path()` `env()` | 路径 / 环境 |
| `logger()` / `Log::` | 日志 |
| `cache()` / `Cache::` | 缓存 |
| `event($e)` / `Event::` | 事件 |
| `validator()->validate($d,$r)` / `Validator::` | 校验 |
| `jwt()->issue($c)` / `Jwt::` | JWT |
| `rateLimit()->consume($k,$n)` / `RateLimit::` | 限流 |
| `breaker()->run($n,$t,$f)` / `Breaker::` | 熔断 |
| `http()->get($u)` / `Http::` | HTTP 客户端 |
| `messaging()` / `Messaging::` | 消息总线 |
| `lang($k,$p)` / `translator()` / `Translator::` | 国际化 |
| `queue()` / `Queue::` · `db()` / `DB::` · `process()` / `Process::` · `snowflake()` / `Snowflake::` | 队列 / 数据库 / 多进程 / 分布式 ID |
| `exception_manager()` / `Exception::` | 异常管理器 |
| `route($name, $params)` | 反向生成 URL |

门面（`Cache`/`DB`/`Log`/`Jwt`/`RateLimit`/`Breaker`/`Http`/`Queue`/`Event`/`Messaging`/`Translator`/`Snowflake`/`Process`/`Validator`/`Exception`）继承 `Kode\Core\Facade`，用静态语法访问容器里的服务实例。

---

## 16. 控制台命令

继承 `Kode\Framework\Console\Command`，用 `#[AsCommand]` 声明；一个命令一个类（kode/console 限制）：

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

内置方法：`arg()` / `flag()` / `opt()` / `info()` / `line()` / `warn()` / `error()` / `success()` / `table()`。运行：`php bin/kode console greet Kode --shout`。

框架内置：`route:list`、`schedule:list`、`cron`（以及 `new` / `serve` 这类 `bin/kode` 一级命令）。

---

## 17. DI 与服务提供者

容器基于 `kode/di`。业务类构造函数 / 属性会自动注入：

```php
final class UserController extends Controller
{
    public function __construct(private UserService $svc) {}

    public function show(): array
    {
        return $this->json($this->svc->find((int) $this->param('id')));
    }
}
```

要注册自己的服务 / 接线点，写 `app/Providers/*ServiceProvider`（继承框架 `ServiceProvider`），并在 `config/app.php` 的 `providers` 数组里追加类名。框架内置 Provider 见 `src/Providers/`（Exception / Log / Cache / Event / Jwt / Validation / Limiting / Database / Queue / HttpClient / Messaging / Resilience / Translation / Http / Snowflake / Process / Console …）。

---

## 18. AOP 切面

基于 `kode/aop` 的原生属性切面，把日志 / 鉴权 / 重试等横切逻辑从业务里抽离：

```php
use Kode\Aop\Attribute\Aspect;
use Kode\Aop\Attribute\Before;

#[Aspect]
final class LogAspect
{
    #[Before(\App\Services\UserService::class)]
    public function before() { logger()->info('调用 UserService'); }
}
```

可用：`#[Aspect]` / `#[Before]` / `#[After]` / `#[Around]` / `#[AfterReturning]` / `#[AfterThrowing]` / `#[Pointcut]` / `#[Priority]`。示例见 `app/Aop/LogAspect.php`。

---

## 19. 插件

`plugins/<name>/` 下的 `src/Controllers`、`routes.php`、调度任务会被**自动发现**，来源标记 `plugin:<name>`：

- 控制器：`plugins/blog/src/Controllers` → 属性路由自动纳入。
- 路由：`plugins/blog/routes.php` → 自动加载。
- 定时任务：`plugins/blog/src/Tasks` → `bin/kode cron` 自动扫描（开启 `discover_plugins`）。

无需在配置里逐条登记。

---

## 20. 部署到生产

```dotenv
# .env（生产）
APP_DEBUG=false
APP_NAME=myapp
JWT_SECRET=<强随机串>
RATE_LIMIT_DRIVER=redis
REDIS_HOST=127.0.0.1
```

```bash
# 用进程管理器拉起（不要用 --watch）
php bin/kode serve --port 80 --workers 8
```

- `APP_DEBUG=false`：错误响应自动收敛细节，只记日志。
- 关闭 `--watch`（那是开发热重载）。
- 多 worker 下，共享状态（限流 / 熔断 / 会话 / 缓存）请用 Redis 等外部存储。
- 用 supervisor / systemd 托管进程，保证崩溃自动重启。

---

## 21. 测试

框架用 PHPUnit。启动应用取容器：`$app = \Kode\Framework\Application::make($root);`，然后 `resolve(Foo::class)` 拿服务。例如断言某控制器方法被登记了限流：

```php
$app = \Kode\Framework\Application::make(dirname(__DIR__));
$registry = resolve(\Kode\Framework\Http\RouteRegistry::class);
// ...断言 registry->rateLimitsOf($route) 非空
```

异常路径可断言 `ExceptionMiddleware` 产出含 `location` / `chain` 的 JSON；JWT 可断言连续签发 `jti` 互不相同且无声明泄漏。
