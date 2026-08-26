# DI 与服务提供者

容器基于 `kode/di`（自动装配 + 生命周期管理 + 上下文绑定）。业务类**构造函数 / 属性自动注入**，无需手动 new：

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

框架在请求管线里把控制器、命令、定时任务、Worker 等都交给容器解析，构造参数按类型自动解析（服务）或按名称回退（标量配置）。

## 1. 从容器取对象

| 方式 | 说明 |
| --- | --- |
| `resolve(SomeService::class)` | 全局助手，即容器 `make()` |
| `resolve(UserService::class, ['id' => 1])` | 带构造参数解析 |
| `resolve('db')` | 按「接口 / 别名」解析（框架把连接、组件都注册成 id） |
| `resolve('events')->dispatch($event)` | 解析后直接调用 |

```php
resolve(\app\services\Greeter::class)->hello('world');
app()->container->call(fn (Greeter $g) => $g->hello('kode'));   // 闭包参数自动装配
```

## 2. 绑定与生命周期

框架启动时会自动装配（`class_exists` 即可解析，无需注册）。需要**接口→实现**、**单例复用**、**覆盖默认实现**时显式绑定：

```php
// 全局以 app()->container 访问容器（app() 助手由 kode/core 提供，见 vendor/kode/core/src/Support/helpers.php）

// 生命周期常量：singleton（默认，进程内共享一份）/ prototype（每次解析新实例）/ lazy（延迟实例化）
app()->container->bind(PaymentGateway::class, AlipayGateway::class);       // 接口 → 实现
app()->container->singleton('cache.redis', fn() => new RedisClient(...));  // 闭包定义
app()->container->prototype('ticket', TicketGenerator::class);             // 每次全新
app()->container->instance('current_user', $user);                         // 预置实例
app()->container->alias('pay', PaymentGateway::class);                     // 短别名
```

- **默认即单例**：`bind()` 第三个参数不传时生命周期为 `SINGLETON`；对象内部有状态（连接、句柄）适合单例，无状态或需要隔离的用 `prototype`；
- **重复绑定覆盖**：后绑定者胜出，可用于测试替身 / 环境差异覆盖；
- **`bindIf` / `singletonIf`**：不存在才绑，避免覆盖框架默认接线。

## 3. 上下文绑定（同一接口，不同消费者不同实现）

```php
app()->container->when(AdminController::class)
    ->needs(PaymentGateway::class)
    ->give(EnterprisePaymentGateway::class);

app()->container->when(StoreController::class)
    ->needs(PaymentGateway::class)
    ->give(AlipayGateway::class);
```

按「消费方类」给出不同实现，适合多租户 / 多网关 / 多数据源场景。

## 4. 方法调用注入（call）

```php
// 控制器/CLI/闭包里按参数类型自动注入
app()->container->call([$controller, 'store']);                 // 方法参数自动装配
$result = app()->container->call(fn (RateLimiter $rl) => $rl->hit('api'), ['extra' => 1]); // 混入标量参数

// 绑定自定义方法处理（某些第三方类不方便改构造时）
app()->container->bindMethod($method, fn ($arg) => /* 自定义解析 */);
```

## 5. 服务提供者（ServiceProvider）

**自定义服务的接线点**。约定：放在 `app/providers/*ServiceProvider.php`（目录全小写），类 `namespace app\Providers;` 继承框架 `ServiceProvider`，然后在 `config/app.php` 的 `providers` 数组追加类名：

```php
<?php

declare(strict_types=1);

namespace app\Providers;

use Kode\Framework\Providers\ServiceProvider;

final class BillingProvider extends ServiceProvider
{
    public function register(): void
    {
        // 只做绑定，不要在这里消费容器（其他 Provider 可能尚未注册）
        $this->container->bind(PaymentGateway::class, AlipayGateway::class);
    }

    public function boot(): void
    {
        // 所有 Provider register 完成后调用：可以安全 resolve / 注册路由 / 订阅事件
        $this->container->singleton('billing.gateway', fn() => $this->container->make(PaymentGateway::class));
    }

    public function provides(): array
    {
        return [PaymentGateway::class];   // 延迟 Provider 声明提供哪些服务
    }
}
```

**register 与 boot 的时序**：先全部 `register()`（只声明绑定，不取用），再全部 `boot()`（可解析、可接线）——所以**初始化逻辑放 `boot()`**，杜绝"用了还没注册的依赖"。

框架内置 Provider 见 `src/Providers/`：Exception / Log / Cache / Event / Jwt / Validation / Limiting / Database / Queue / HttpClient / Messaging / Resilience / Translation / Http / Snowflake / Process / Console / ApiDoc / Observability 等。

## 6. 门面（Facade）与助手

常用组件通过门面 / 全局助手访问，背后仍是同一个容器对象：

```php
config('app.debug')      // 等价 kode/core 全局助手，见 vendor/kode/core/src/Support/helpers.php
cache()->get('k')        // 缓存门面（src/Facades/Cache.php，背后是 CacheManager 单例）
logger()->info('...')    // 日志
```

> 门面类位于 `src/Facades/`（命名空间 `Kode\Framework\Facades`，如 `Cache` / `Log` / `DB` / `Event` / `Queue` / `Http` / `Process` 等），每个门面是容器 id 的静态代理；全局助手由 kode/core 与 `src/Support/helpers.php` 提供：`app()` / `config()` / `runtime()` / `resolve()` / `logger()` / `cache()` / `db()` / `event()` / `validator()` / `jwt()` / `queue()` / `session()` / `aop()` / `parallel()` / `graceful()` 等。

## 7. 测试与环境覆盖

- 测试基类 `Kode\Framework\Testing\TestCase` 通过 `configOverrides` 透传启动期配置覆盖（`Application::make($root, $overrides)`），最高优先级合并进 `config/`；
- 测试中先 `app()->container->instance($id, $mock)` 即可替换组件，`bindIf` / `instanceIf` 保证不误覆框架默认。

---