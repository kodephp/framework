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

在控制器类 / 方法上用属性声明，启动时自动扫描 `app/http/controllers`（递归子目录，新建子文件夹即一个模块）：

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
}, [new \app\http\middleware\ApiGuard()]);
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

### 1.7 中间件执行顺序

单条路由挂多个中间件、以及「路由级 + 分组级 + 全局」叠挂时，按**声明逆序**执行（洋葱模型）：

```php
// 请求进入顺序：Auth → ApiGuard → 控制器；响应反向返回
$app->group('/api', function (App $app): void {
    $app->get('/x', fn() => /* ... */)
        ->middleware(new AuthMiddleware());   // 最内层，最后进入
}, [new ApiGuardMiddleware()]);               // 分组级，先进入

// 等价于：ApiGuard => Auth => handler => Auth 响应 => ApiGuard 响应
```

### 1.8 参数约束速查

| 写法 | 匹配 | 示例 |
| --- | --- | --- |
| `{id}` | 任意非空段 | `/users/123`、`/users/abc` |
| `{id:\d+}` | 仅数字 | `/users/123` ✓，`/users/abc` ✗ |
| `{amount:\d+\.\d+}` | 小数（类型推导为 `number`） | `/price/9.99` |
| `{name}?` | 可选段 | `/files` 与 `/files/a.txt` 均可 |

路径参数在控制器用 `$this->param('id')` 读取；闭包 / 中间件 / 服务等其他位置用 `Request::param('id')`（`Kode\Http\Request` 静态方法）。

---

## 2. 多应用路由（零开关）

框架除主应用（`app/`）外，**自动发现子应用**：只要 `app/{App}/routes.php` 存在，即视为一个子应用，无需任何开关或登记。

```
app/
├── routes.php              # 主应用路由
├── http/controllers/       # 主应用控制器（属性路由）
├── admin/                  # 子应用 admin
│   ├── routes.php          # app/admin/routes.php 存在 → 自动注册
│   └── http/controllers/   # （或 controllers/）自动纳入属性路由扫描
└── api/
    └── routes.php          # 子应用 api
```

- **子应用路由文件**：`app/{App}/routes.php` + `app/{App}/routes/*.php`（与主应用同规则，自动 glob）；
- **子应用控制器**：`app/{App}/http/controllers`（或 `app/{App}/controllers`）自动纳入属性路由扫描，`route:list` 按来源分组展示标签 `app:<App>`；
- **命名空间**：子应用类沿用全小写约定，如 `app\admin\http\controllers\...`，控制器方法级属性照常生效；
- **独立模块化**：一个子应用即一个可独立组织路由 / 控制器 / namespace 的模块，适合按业务域、供应商插件或团队边界拆分，互不干扰。

`route:list` 全量展示会按来源分组：

```bash
php bin/kode console route:list            # app / app:admin / app:api 分组并列
php bin/kode console route:list --source=app:admin   # 只看 admin 子应用
```

---

