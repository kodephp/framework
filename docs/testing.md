# 测试

框架用 PHPUnit。启动应用取容器：`$app = \Kode\Framework\Application::make($root);`，然后 `resolve(Foo::class)` 拿服务。例如断言某控制器方法被登记了限流：

```php
$app = \Kode\Framework\Application::make(dirname(__DIR__));
$registry = resolve(\Kode\Framework\Http\RouteRegistry::class);
// ...断言 registry->rateLimitsOf($route) 非空
```

异常路径可断言 `ExceptionMiddleware` 产出含 `location` / `chain` 的 JSON；JWT 可断言连续签发 `jti` 互不相同且无声明泄漏。
