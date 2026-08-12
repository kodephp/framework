# 路由全解

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

