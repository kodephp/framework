# AOP 切面

基于 `kode/aop` 的属性切面：把日志 / 鉴权 / 重试 / 缓存 / 事务等横切逻辑从业务里抽离。切面通过 **切入点表达式** 声明"我要织入哪里"，框架生成目标类的**代理对象**，调用代理即触发切面。

## 1. 最小示例（Before）

`app/aop/LogAspect.php`：

```php
<?php

declare(strict_types=1);

namespace app\aop;

use Kode\Aop\Attribute\Aspect;
use Kode\Aop\Attribute\Before;
use Kode\Aop\Runtime\JoinPoint;

#[Aspect]
final class LogAspect
{
    #[Before('execution(* \app\services\Greeter->hello(..))')]
    public function beforeHello(JoinPoint $joinPoint): void
    {
        logger()->info(sprintf(
            'AOP[before] %s::%s args=%s',
            $joinPoint->getClassName(),
            $joinPoint->getMethodName(),
            json_encode($joinPoint->getArguments(), JSON_UNESCAPED_UNICODE)
        ));
    }
}
```

## 2. 切入点表达式

| 语法 | 匹配 |
| --- | --- |
| `execution(* App\Service\*->*(..))` | 某命名空间下**所有类**的所有方法 |
| `execution(* App\Service\UserService->createUser(..))` | 精确到**类 + 方法**（`(..)` 任意参数） |
| `execution(* *->send*(..))` | 以 `send` 开头的方法 |
| `execution(public App\Payment\*->process())` | 匹配特定可见性 + 精确签名 |
| `within(App\Controller\*)` | 某命名空间下所有类（含其方法） |

通配：`*` 匹配任意类 / 任意返回类型，`..` 匹配任意参数列表。

## 3. 通知类型与执行顺序

六个通知属性（方法级，可重复），围绕目标方法构成完整环绕链：

| 属性 | 时机 | 拿到什么 |
| --- | --- | --- |
| `#[Before(exp)]` | 目标方法执行前 | `JoinPoint`：参数、类 / 方法名 |
| `#[Around(exp)]` | 包裹目标方法（可改参数 / 返回值 / 异常） | `ProceedingJoinPoint`：必须调 `proceed()` 才放行 |
| `#[AfterReturning(exp)]` | 目标方法正常返回后 | `getResult()`（返回值） |
| `#[AfterThrowing(exp, throwable)]` | 目标方法抛异常时 | `getException()`；`throwable` 限只处理某异常类型 |
| `#[After(exp)]` | 无论成败都执行（finally） | `JoinPoint` |
| `#[Around(exp)]` | 见上 | 链上最灵活的一环 |

完整通知链（Around 最外层）：

```
Around.before → Before → 目标方法 → AfterReturning / AfterThrowing → After → Around.after
```

代码示意：

```php
use Kode\Aop\Attribute\Around;
use Kode\Aop\Runtime\ProceedingJoinPoint;

#[Around('execution(* \app\services\Greeter->hello(..))')]
public function timeIt(ProceedingJoinPoint $jp): mixed
{
    $start = microtime(true);
    $result = $jp->proceed();                      // 放行目标方法
    logger()->info(sprintf('耗时 %.4fs', microtime(true) - $start));
    return $result;
}

#[AfterThrowing('execution(* \app\services\*->*(..))', throwable: \RuntimeException::class)]
public function onError(JoinPoint $jp): void
{
    logger()->error(get_class($jp->getException()) . ': ' . $jp->getException()->getMessage());
}
```

> `proceed()` 可传新参数数组覆盖原参；异常不会因 `AfterThrowing` 被吞掉——切面里若要转换异常，请用 `Around` + try/catch。

## 4. 优先级（多个切面命中同一方法时）

- `#[Aspect(priority: N)]` 类级声明，**数字越小优先级越高**（默认 0）；
- 高优先级切面在外层（先执行 Before / 后执行 After），低优先级在内层；
- 同一优先级按注册顺序稳定执行。

```php
#[Aspect(priority: -10)]   // 最高优先：鉴权切面先于日志切面执行
final class AuthAspect { /* ... */ }
```

## 5. 声明式增强属性（类 / 方法级，无需写代码）

`kode/aop` 还内置四个开箱即用的方法级属性：

| 属性 | 作用 |
| --- | --- |
| `#[Cache(ttl: 60, key: null, prefix: 'aop')]` | 方法结果缓存；`key` 缺省按「类名::方法 + 参数」自动生成，`prefix` 便于按业务隔离与批量清理 |
| `#[Log(level, message, logArgs, logResult, logException, includeTime)]` | 自动记录入参 / 返回值 / 异常 / 耗时 |
| `#[Transactional(name: null)]` | 方法级事务（配合数据库连接） |
| `#[Pointcut(expression)]` | 复用切入点表达式，供多个通知引用 |

## 6. 注册与生效（代理）

切面注册后**不会改变原类**——必须通过 `Aop::proxy()` / `Aop::wrap()` 获取代理对象才会触发织入：

```php
use Kode\Aop\Aop;

// 1) 注册切面实例（启动期由框架按 config/aop.php 自动完成）
Aop::register(LogAspect::class);

// 2) 从容器解析时用代理
$greeter = Aop::proxy(\app\services\Greeter::class);   // 返回带切面的代理
$greeter->hello('world');                              // 触发 before / around 等

// 已有实例也可包装：Aop::wrap($instance)
```

> 直接在控制器里 `resolve(Greeter::class)` 解析到的是**原生实例**；要让切面生效，请统一通过 `Aop::proxy()` 获取，或把「获取代理」收口为一个工厂 / Provider。框架按 `config/aop.php` 配置在启动期注册切面并预编译表达式（可选缓存目录）。

## 7. 常见坑

- **类内部自调用不织入**：`$this->method()` 不走代理，切面不会触发；跨类调用 / 通过代理调用才生效；
- **final 类也能代理**：代理工厂自动二选一——非 `final` 类走**继承式**（`class X__AopProxy extends X`，可织入 `protected` 方法）；`final` 类走**组合式**（实现原类的接口，只织入 `public` 方法）；
- **final 方法 / private 方法不织入**：继承式代理对 `final` 方法与 `private` 方法无效（无法覆写）；组合式对接口外的 `public` 方法也无效；
- **构造注入与代理**：`proxy(Class::class, [...构造参数])` 支持传构造参数；无参类直接 `proxy(Class::class)`；
- **AfterThrowing 不吞异常**：异常在切面处理后仍继续向上抛。

> 示例见 `app/aop/LogAspect.php` 与 `config/aop.php`；测试参考 `tests/AopProviderTest.php`。

---