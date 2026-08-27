# 自己写中间件

中间件实现 PSR-15 的 `MiddlewareInterface`，包一层逻辑后调用 `$handler->handle($request)`：

```php
namespace app\http\middleware;

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

