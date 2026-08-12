# AOP 切面

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

