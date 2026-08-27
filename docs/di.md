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

## 8. 属性注解式 DI（kode/di 最新版）

最新版 `kode/di` 支持用 PHP 8 属性（Attribute）声明生命周期与注入，多数场景无需再手写 `bind`：

| 属性 | 位置 | 作用 |
| --- | --- | --- |
| `#[Singleton]` | 类 | 首次 `resolve(X::class)` 时自动注册为**单例**（进程内共享一份） |
| `#[Prototype]` | 类 | 每次 `resolve(X::class)` 都返回**全新实例** |
| `#[Contextual]` | 类 | 注册为**上下文隔离**服务（按消费方不同给不同实现） |
| `#[Autowire]` | 类 | 标记该类参与属性驱动的自动装配 |
| `#[Inject(id: X::class)]` | 构造参数 / 属性 | 注入指定容器 id，绕过按类型解析 |

> 属性类位于 `Kode\DI\Attributes\*`（Singleton / Prototype / Contextual / Autowire / Inject）。

容器在 `resolve()` 时自动读取这些属性并据此绑定，因此下面这样即可直接用，不必提前注册：

```php
use Kode\DI\Attributes\Singleton;
use Kode\DI\Attributes\Prototype;

#[Singleton]
final class CacheManager
{
    public function get(string $k): mixed { /* ... */ }
}

#[Prototype]
final class RequestId
{
    public string $id = '';   // 每次解析都是新值
}

// 使用时
$cm1 = resolve(CacheManager::class);
$cm2 = resolve(CacheManager::class);
$cm1 === $cm2;                // true：单例

$rid1 = resolve(RequestId::class);
$rid2 = resolve(RequestId::class);
$rid1 !== $rid2;              // true：原型，每次新建
```

### 8.1 构造函数按 id 注入（#[Inject]）

当同一个类型有多个实现、或想显式指定容器 id 时，在参数上加 `#[Inject]`：

```php
use Kode\DI\Attributes\Inject;

final class OrderService
{
    public function __construct(
        #[Inject(id: PaymentGateway::class)]
        private Gateway $gateway,
    ) {}
}
```

属性注入同理（注意：属性注入在对象 `new` 之后发生，依赖该属性被标注 `#[Inject]`）：

```php
final class ReportService
{
    #[Inject(id: 'cache.redis')]
    private RedisClient $redis;
}
```

### 8.2 上下文绑定的属性版（#[Contextual]）

`#[Contextual]` 等价于代码版的 `when()->needs()->give()`：被标注的类会按"消费方"解析出不同实现。配合 `when()` 配置消费方映射即可：

```php
use Kode\DI\Attributes\Contextual;

#[Contextual]
final class TenantRedis extends RedisClient { /* 按租户隔离的连接 */ }
```

```php
app()->container->when(AdminController::class)
    ->needs(RedisClient::class)
    ->give(TenantRedis::class);
```

### 8.3 与"自动装配"的关系

框架默认就"类存在即可解析"（`class_exists` 即自动装配），属性注解是在此之上做**精细化**：

- 默认 `bind()` 是单例；想改成每次新建，给类加 `#[Prototype]` 即可，无需改绑定代码；
- 想让某个参数注入特定 id 而非按类型，用 `#[Inject(id: ...)]`；
- 想让某服务随消费方变化，用 `#[Contextual]`。

---