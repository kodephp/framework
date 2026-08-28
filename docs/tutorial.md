# Kode Framework 实战教程：从 0 到上线一个博客 API + 管理后台

> 适用版本：v0.8.51 ｜ 阅读时长：约 30 分钟 ｜ 配套示例：`examples/api-admin-demo`
>
> 本教程面向从零开始的同学，也覆盖进阶话题。你可以把它当成一篇「博客式」上手指南：从 Composer 安装讲起，一路做到「公开博客 API + JWT 鉴权的管理后台」，最后聊单/多应用组织、部署与压测。

---

## 0. 先搞清楚：Kode 是什么，为什么选它

Kode 是一个**常驻内存**的 PHP 应用框架。它把请求交给 **Swoole / Swow / Fiber / 多进程** 任意一种运行时跑，但业务代码写的是**原生 PHP 语法**——你不需要为了"协程"把代码改成回调地狱，也不强制用注解驱动（但你想用就有）。

### 0.1 和 webman / hyperf 比，差异在哪

| 维度 | webman | hyperf | **kode** |
| --- | --- | --- | --- |
| 常驻内存 | ✅（基于 workerman） | ✅（基于 swoole） | ✅（**默认 kode/process 原生多进程**，可切 Swoole/Swow/Fiber） |
| 协程支持 | ⚠️ 弱（主要同步风格） | ✅（注解 + `@Inject` 强约束） | ✅（原生语法 + 可选协程反压） |
| 路由风格 | 闭包路由为主 | 注解路由为主 | **闭包 + 注解都一流** |
| DI | 手动配置较多 | 注解驱动、规则重 | 自动装配 + 属性注解（`#[Singleton]` 等），心智负担小 |
| AOP | 无 | 有（注解切面） | 有（注解切面，零侵入） |
| 学习曲线 | 低 | 中高（概念多） | 低（用 PHP 本身的概念） |
| 多运行时 | 单（workerman） | 单（swoole） | **多**（**默认原生多进程**，同一份代码换运行时免改） |

### 0.2 Kode 的核心优势（也是本教程会落到实处的点）

1. **常驻内存 + 原生 PHP**：一次启动，请求反复复用容器、连接池、路由表；代码还是你熟悉的 `class`/`function`。
2. **注解路由（Hyperf 风格）**：`#[Controller]` + `#[Get]` 即可定义路由，IDE 跳转友好。
3. **零配置 DI**：构造函数类型提示自动注入；最新版 `kode/di` 还能用 `#[Singleton]`/`#[Prototype]`/`#[Inject]` 声明生命周期与注入来源。
4. **JWT / AOP / 事件 / 限流 / 可观测性** 都是框架内置，不是第三方拼装。
5. **多应用、多运行时** 一套代码通吃，部署灵活。

> 本教程的最终产物 `examples/api-admin-demo` 就是一个"博客 API + 管理后台"的开源示例，演示上述全部能力。

---

## 1. 环境准备

| 依赖 | 版本 / 说明 |
| --- | --- |
| PHP | **>= 8.3** |
| Composer | 2.x |
| 扩展（推荐） | `pdo_sqlite` 或 `pdo_mysql`；`swoole` / `swow` 可选（不装也能跑，只是不走协程） |
| 缓存（可选） | `redis` 扩展，用于分布式缓存/队列 |

检查：

```bash
php -v            # >= 8.3
composer -V
php -m | grep -E 'pdo_sqlite|pdo_mysql|swoole'
```

---

## 2. 用 Composer 安装 / 创建项目

### 2.1 创建你的项目（独立目录，不要放进框架里）

> 你的业务项目是**一个独立目录**，由 Composer 拉取 `kode/framework` 作为依赖；**不要把项目生成在框架源码目录内部**。

发布到 Packagist 后，用官方脚手架在任意空目录创建（会自动落在当前目录之外）：

```bash
composer create-project kode/framework my-app
cd my-app
```

尚在开发期（未发布）时，把框架仓库里的骨架目录 `app/`、`config/`、`public/`、`bin/`、`lang/`、`database/` 复制到你的项目根目录即可（或直接以框架仓库为工作区开发）。

### 2.2 在已有项目里引入

```bash
composer require kode/framework
```

然后把框架的骨架目录 `app/`、`config/`、`public/`、`bin/`、`lang/`、`database/` 拷进你的工程根目录。

### 2.3 目录约定（非常重要）

框架铁律：**`app/` 下所有目录一律小写**；命名空间（namespace）同样**全小写**，与目录一一对应；只有**类名 / 文件名**（首字母大写驼峰）和**方法名**（camelCase）使用驼峰。

| 项 | 规则 | 示例 |
| --- | --- | --- |
| 目录 | 全小写 | `app/http/controllers` |
| 命名空间 | 全小写，与目录对应 | `app\http\controllers` |
| 类 / 文件 | 首字母大写驼峰 | `PostController.php` |
| 方法 | camelCase | `index()` / `store()` |

`composer.json` 已配 `"app\\": "app/"`，走 PSR-4：在 `app/` 下新增任意类都**即时可加载，无需 `composer dump-autoload`**。`#[Controller]`/`#[Get]` 等属性路由会在**启动时递归扫描** `config/routes.php` 里配置的控制器目录自动注册；改完控制器**重启服务即生效**（开发期加 `--watch` 热重载自动重启）。只有当你改了 `composer.json` 的 `autoload` 映射本身时，才需要 `composer dump-autoload`。

### 2.4 首次启动

```bash
php bin/kode serve          # 默认 127.0.0.1:9527（属性路由启动时自动扫描，无需 dump-autoload）
```

浏览器/ curl 访问 `http://127.0.0.1:9527` 能看到欢迎页即成功。

---

## 3. 第一个请求：闭包路由

最轻量的路由是**闭包路由**，写在 `app/routes.php`：

```php
<?php

declare(strict_types=1);

use Kode\Framework\Http\Resp;
use Kode\Http\App;

App::get('/hello', static fn () => Resp::json([
    'message' => 'Hello Kode',
]));
```

启动后访问 `http://127.0.0.1:9527/hello` 即可返回 JSON。

常用写法：

```php
App::get('/users/{id}', static function (int $id) {
    return Resp::json(['id' => $id]);
});

App::post('/users', static function (Request $req) {
    $name = $req->input('name', 'world');
    return Resp::json(['hello' => $name]);
});
```

- `App::get/post/put/patch/delete/any` 注册路由；
- `Resp::json($data, $status = 200)` / `Resp::ok` / `Resp::error($msg, $code)` 返回响应；
- 路由参数（`{id}`）按名注入到闭包/控制器方法的同名参数。

---

## 4. 注解路由 + 控制器（Hyperf 风格）

业务上更推荐用**注解路由 + 控制器**，IDE 可跳转、可分组、可加中间件。

### 4.1 定义控制器

```php
<?php

declare(strict_types=1);

namespace app\http\controllers;

use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Controller as BaseController;
use Kode\Framework\Http\Request;

#[Controller(prefix: '/api/demo')]
final class DemoController extends BaseController
{
    #[Get('/hello/{name}')]
    public function hello(string $name, Request $req): mixed
    {
        // 基类助手：validate / json / error / query / input
        return $this->json([
            'name'    => $name,
            'from'    => $req->query('from', 'curl'),
            'framework' => 'kode',
        ]);
    }
}
```

要点：

- `#[Controller(prefix: '/api/demo')]` 给整个控制器加前缀；
- `#[Get('/hello/{name}')]` / `#[Post(...)]` / `#[Put]` / `#[Delete]` / `#[Route(['POST','PUT'], '/x')]` 定义方法级路由；
- 路由参数 `{name}` 自动注入方法参数；`Request $req` 按类型注入；
- 继承 `Kode\Framework\Http\Controller` 可获得 `validate()`、`json()`、`error()`、`query()`、`input()` 等助手。

### 4.2 路由是怎么被发现的

`config/routes.php` 里开启注解扫描并列出控制器目录：

```php
return [
    'attributes' => [
        'enabled' => true,
        'controllers' => [
            'app'   => 'app/http/controllers',
            'admin' => 'app/admin/http/controllers',   // 后续多应用会用到
        ],
    ],
];
```

框架**启动时递归扫描**这些目录，把 `#[Controller]` / `#[Get]` 等注解注册成路由——新增/修改控制器后**重启服务即生效**（开发期用 `php bin/kode serve --watch` 热重载，无需手动 dump-autoload）。

### 4.3 请求与响应

```php
// 取参
$req->input('name');          // 任意来源
$req->query('page');          // URL 查询串
$req->post('title');          // 表单/JSON body
$req->header('x-token');
$req->file('upload');

// 响应
return $this->json($data);            // 200
return $this->json($data, 201);       // 自定义状态码
return $this->error('参数错误', 422); // 业务错误
```

---

## 5. 依赖注入（DI）

### 5.1 自动装配（开箱即用）

只要类型能被解析，构造函数参数**自动注入**：

```php
final class PostController extends BaseController
{
    public function __construct(private PostService $svc) {}

    #[Get('')]
    public function index(): mixed
    {
        return $this->json($this->svc->paginate());
    }
}
```

框架在请求管线里解析控制器、命令、定时任务、Worker，构造参数按类型自动解析。

### 5.2 手动绑定（接口→实现、单例/原型）

需要接口映射或控制生命周期时显式绑定（`app()->container`）：

```php
app()->container->bind(PaymentGateway::class, AlipayGateway::class);  // 接口→实现
app()->container->singleton('cache.redis', fn() => new RedisClient()); // 单例闭包
app()->container->prototype('ticket', TicketGenerator::class);         // 每次新建
app()->container->instance('current_user', $user);                    // 预置实例
app()->container->alias('pay', PaymentGateway::class);                // 别名
```

### 5.3 属性注解式 DI（kode/di 最新版）

最新版 `kode/di` 支持用 PHP 8 属性声明生命周期，**多数场景不再需要手写 bind**：

```php
use Kode\DI\Attributes\Singleton;
use Kode\DI\Attributes\Prototype;
use Kode\DI\Attributes\Inject;

#[Singleton]                 // resolve() 时自动注册为单例
final class CacheManager { /* ... */ }

#[Prototype]                // 每次解析都新建
final class RequestId { /* ... */ }

final class OrderService
{
    public function __construct(
        #[Inject(id: PaymentGateway::class)]   // 注入指定 id，而非按类型
        private Gateway $gateway,
    ) {}
}
```

| 属性 | 作用 |
| --- | --- |
| `#[Singleton]` | 单例（进程内共享） |
| `#[Prototype]` | 每次全新实例 |
| `#[Contextual]` | 上下文隔离服务（按消费方给不同实现） |
| `#[Autowire]` | 标记参与属性驱动自动装配 |
| `#[Inject(id: X::class)]` | 构造参数/属性注入指定容器 id |

> 更完整说明见 [`docs/di.md`](./di.md)。

### 5.4 中间件

中间件实现 `Kode\Framework\Http\Middleware`，`handle(Request, callable $next)` 返回响应或 `$next($request)`：

```php
use Kode\Framework\Http\Middleware;
use Kode\Framework\Http\Request;

final class CorsMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $resp = $next($request);
        return $resp->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

注册方式：控制器类/方法上加 `middleware`：

```php
#[Controller(prefix: '/admin', middleware: [AuthMiddleware::class, AdminMiddleware::class])]
```

或闭包路由：`App::get('/x', $handler, [AuthMiddleware::class])`。

---

## 6. JWT 鉴权

### 6.1 配置

`config/jwt.php` 定义 guard、secret、ttl：

```php
return [
    'default' => 'api',
    'guards'  => [
        'api' => [
            'secret' => env('JWT_SECRET', 'dev-secret-change-me'),
            'ttl'    => (int) env('JWT_TTL', 3600),
            'algo'   => 'HS256',
        ],
    ],
];
```

### 6.2 登录签发 token

```php
#[Post('/login')]
public function login(Request $req): mixed
{
    $data = $this->validate($req->all(), [
        'username' => 'required',
        'password' => 'required',
    ]);

    $user = User::where('username', $data['username'])->first();
    if ($user === null || !password_verify($data['password'], $user->password)) {
        return $this->error('用户名或密码错误', 401);
    }

    $token = jwt()->issue([
        'uid'      => $user->id,
        'username' => $user->username,
        'roles'    => [$user->role],
        'custom'   => ['display_name' => $user->display_name],
    ]);

    return $this->json(['token' => $token]);
}
```

`jwt()` 是全局助手，背后是 `JwtGuard`。`issue()` 接收 claims（`uid`/`username`/`roles`/`custom` 等），返回字符串 token。

### 6.3 保护路由 + 取当前用户

框架内置 `AuthMiddleware`，会在认证成功后把 `Payload` 挂到 `Request::attr('auth')`：

```php
#[Get('/me', middleware: [AuthMiddleware::class])]
public function me(): mixed
{
    $payload = Request::attr('auth');   // Kode\Jwt\Token\Payload
    return $this->json([
        'id'   => $payload->uid,
        'name' => $payload->username,
        'roles' => $payload->roles ?? [],
    ]);
}
```

客户端请求时带 `Authorization: Bearer <token>`。自定义角色校验中间件见 `examples/api-admin-demo/app/http/middleware/AdminMiddleware.php`。

---

## 7. 数据库与模型（ORM）

### 7.1 连接与查询构造器

```php
use Kode\Framework\Database\Db;

$rows = Db::table('posts')
    ->where('status', 'published')
    ->orderBy('published_at', 'desc')
    ->paginate(10);          // 返回数组：['items'=>[...],'total'=>,'per_page'=>,'current_page'=>,'last_page'=>]
```

### 7.2 模型（Eloquent 风格）

```php
namespace app\models;

use Kode\Framework\Database\Model;

final class Post extends Model
{
    protected string $table = 'posts';
    protected array $fillable = ['title', 'content', 'status', 'category_id', 'author_id'];

    public function category() { return $this->belongsTo(Category::class, 'category_id'); }
    public function author()   { return $this->belongsTo(User::class, 'author_id'); }
}
```

常用：

```php
Post::create([...]);                       // 新建并返回模型
Post::find($id);                           // 按主键查，返回 ?Model
Post::where('status', 'published')->first();
$post->fill([...]); $post->save();         // 更新
$post->delete();                           // 删除
$post->toArray();                          // 转数组
$post->category;                           // 懒加载关联
```

> 注意：`Model::paginate($page, $perPage, $orderField, $orderDirection)` 返回**纯数组**且会重置排序；带筛选的分页请用 `Db::table(...)->where(...)->paginate($perPage)`（返回带 `items` 的数组）。

### 7.3 迁移

```php
use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

final class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Schema $t): void {
            $t->id();
            $t->string('title', 255);
            $t->text('content');
            $t->string('status', 32)->default('draft');
            $t->integer('category_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('posts');
    }
}
```

运行：

```bash
php bin/kode migrate          # 执行迁移
php bin/kode migrate:rollback # 回滚
```

### 7.4 填充（Seeder）

```php
use Kode\Framework\Database\Seeder;
use app\models\User;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('username', 'admin')->first() === null) {
            User::create([
                'username' => 'admin',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'role'     => 'admin',
            ]);
        }
    }
}
```

```bash
php bin/kode db:seed
```

> 详见 [`docs/database.md`](./database.md)。

---

## 8. 实战：博客 API + 管理后台（api-admin-demo）

配套完整示例在 `examples/api-admin-demo`，它把上面所有概念串起来。

### 8.1 目标

- **公开 API**：`GET /api/posts`（分页/按分类/关键词筛选）、`GET /api/posts/{id}`；
- **后台 API**：登录、仪表盘统计、文章增删改查，**需 `admin` 角色**；
- 演示**单应用 + 多目录（多应用思路）**的组织方式。

### 8.2 目录结构

```
app/
  http/
    controllers/        # 公开 API（注解路由）
    middleware/          # AdminMiddleware（角色校验）
  admin/
    http/controllers/    # 后台 API（独立目录 = 多应用思路）
  models/                # User / Category / Post
  routes.php             # 闭包路由（健康检查）
config/  routes.php database.php jwt.php app.php
database/
  migrations/            # 3 张表
  seeders/               # 管理员 + 示例数据
public/index.php         # PHP 内置服务器入口
bin/kode                 # 命令入口
```

### 8.3 关键代码

**公开文章列表**（带筛选分页）`app/http/controllers/PostController.php`：

```php
#[Controller(prefix: '/api/posts')]
final class PostController extends BaseController
{
    #[Get('')]
    public function index(Request $req): mixed
    {
        $perPage = max(1, (int) $req->input('per_page', 10));
        $builder = Db::table('posts')->where('status', 'published');
        if ($cat = $req->input('category')) {
            $builder->where('category_id', (int) $cat);
        }
        $result = $builder->orderBy('published_at', 'desc')->paginate($perPage);
        // $result['items'] 是数组；可再关联分类后返回
        return $this->json(['data' => $result['items'], 'meta' => [
            'total' => $result['total'], 'current_page' => $result['current_page'],
        ]]);
    }
}
```

**后台文章管理**（受 `AuthMiddleware` + `AdminMiddleware` 保护）`app/admin/http/controllers/PostAdminController.php`：

```php
#[Controller(prefix: '/admin/api/posts', middleware: [AuthMiddleware::class, AdminMiddleware::class])]
final class PostAdminController extends BaseController
{
    #[Post('')]
    public function store(Request $req): mixed
    {
        $data = $this->validate($req->all(), [
            'title' => 'required', 'content' => 'required',
            'category_id' => 'required', 'status' => 'required',
        ]);
        $post = Post::create([
            'title' => $data['title'],
            'content' => $data['content'],
            'status' => $data['status'],
            'category_id' => (int) $data['category_id'],
            'author_id' => (int) Request::attr('auth')->uid,
            'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
        return $this->json($post->toArray(), 201);
    }
}
```

**角色中间件** `app/http/middleware/AdminMiddleware.php`：

```php
final class AdminMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $payload = $request->attr('auth');
        if ($payload === null) return Resp::error('未认证', 401);
        if (!in_array('admin', $payload->roles ?? [], true)) {
            return Resp::error('无权限访问后台', 403);
        }
        return $next($request);
    }
}
```

### 8.4 跑起来

```bash
cd examples/api-admin-demo
composer install
cp .env.example .env
php bin/kode migrate
php bin/kode db:seed
php bin/kode serve
```

```bash
# 登录拿 token
TOKEN=$(curl -s -X POST http://127.0.0.1:9527/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}' | php -r 'echo json_decode(file_get_contents("php://stdin"))->token;')

# 公开列表
curl http://127.0.0.1:9527/api/posts

# 后台列表（带 token）
curl http://127.0.0.1:9527/admin/api/posts -H "Authorization: Bearer $TOKEN"
```

默认管理员：`admin` / `admin123`（来自 seeder）。

---

## 9. 单应用 vs 多应用

### 9.1 单应用（最常见）

一个 `app/routes.php`（闭包）+ 一个 `app/http/controllers/` 目录 + 一套 `config/`。教程前面都是单应用写法。适合绝大多数 API 服务、中后台。

### 9.2 多应用（按目录隔离）

当一份代码要同时承载多个"子站"（例如 `www` 前台 + `admin` 后台 + `api` 开放接口），用**目录隔离**最清晰：

```
app/
  http/controllers/       # 前台 API
  admin/http/controllers/ # 后台（独立命名空间 + 独立路由前缀）
  api/http/controllers/   # 开放 API
```

在 `config/routes.php` 的 `attributes.controllers` 里把每个目录都列出来即可，路由前缀用 `#[Controller(prefix: ...)]` 区分。本示例正是这种结构（`app/http/controllers` 与 `app/admin/http/controllers`）。

### 9.3 真正的"子应用"

若要让 `app/admin/routes.php` 成为一个独立子应用（独立域名/子目录、独立中间件栈），框架会自动把 `app/admin/routes.php` 识别为名为 `admin` 的子应用。闭包路由写进对应文件，注解控制器仍由上面的扫描目录覆盖。多域名部署时，在 Nginx 按 `server_name` 分流到同一份常驻进程的不同路由前缀即可。

---

## 10. 进阶能力（都用得上）

### 10.1 AOP 切面（零侵入）

用 `#[Aspect]` + `#[Pointcut]` 在方法前后织入逻辑（日志、缓存、耗时统计），不改业务代码。详见 [`docs/aop.md`](./aop.md)。

### 10.2 事件与监听

`event(new UserRegistered($user))` + 监听器解耦副作用（发邮件、写审计）。详见 [`docs/events.md`](./events.md)。

### 10.3 缓存 / 限流 / 并发

- `cache()->get/set` 门面，支持 redis/file；
- 内置限流（`RateLimiter`），防刷接口；
- `parallel()` 并发执行多个 IO 任务（协程反压），适合聚合多个下游服务。

### 10.4 可观测性

框架内置 metrics / tracing / 结构化日志，常驻进程下可对接 Prometheus + Grafana。详见 [`docs/observability.md`](./observability.md)。

### 10.5 验证 / 异常 / 国际化

- `validate($data, $rules)` 自带，失败自动 422；
- 异常处理器统一返回 JSON；
- `Lang::get('key')` 多语言。详见 [`docs/validation.md`](./validation.md)、[`docs/i18n.md`](./i18n.md)。

---

## 11. 部署与压测

### 11.1 常驻启动

```bash
php bin/kode serve --host 0.0.0.0 --port 9527 --workers 8
```

`--workers` 一般设为 CPU 核数。默认即用 **kode/process 原生多进程**常驻，无需 Swoole 也能跑满多核；如需协程 / IO 反压，加 `--runtime swoole`（或 `KODE_RUNTIME=swoole`）切换即可。

### 11.2 Nginx 反向代理

```nginx
location / {
    proxy_pass http://127.0.0.1:9527;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
}
```

### 11.3 压测口径（重要）

跨机器/跨构建的**绝对 rps 不能直接比**。结论只能以「同构建 A/B 相对比值」表述：以 webman 为基线，kode 在多数场景 **≥ 95% 持平、高频 IO 场景可反超**。统一口径：`wrk -t8 -c200 -d6s ×3 取中位数` + 冷却式起停。完整结论见 [`docs/benchmarks.md`](./benchmarks.md)。

---

## 12. 总结 & 开源

你已经从零走到一个可上线的「博客 API + 管理后台」：

- **安装**：Composer 一把梭（`composer install` 即可，PSR-4 自动加载，`app/` 下新增类无需 dump-autoload）；
- **路由**：闭包 + 注解两套都顺手；
- **DI**：自动装配 + `#[Singleton]`/`#[Inject]` 属性注解；
- **鉴权**：JWT 内置，`AuthMiddleware` 一行保护；
- **数据**：Eloquent 风格 ORM + 迁移 + 填充；
- **组织**：单应用起步，多目录/子应用平滑扩展。

完整可运行示例就在 **`examples/api-admin-demo`**（MIT 风格，欢迎 fork / 提 PR）。建议把它 `git clone` 下来 `composer install && php bin/kode serve` 跑一遍，再对照本教程逐行改，印象最深。

下一步可深入：`docs/di.md`、`docs/http-server.md`、`docs/database.md`、`docs/auth.md`、`docs/aop.md`、`docs/observability.md`。
