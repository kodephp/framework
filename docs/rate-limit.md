# 限流

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

