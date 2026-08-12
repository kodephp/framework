# DI 与服务提供者

容器基于 `kode/di`。业务类构造函数 / 属性会自动注入：

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

要注册自己的服务 / 接线点，写 `app/Providers/*ServiceProvider`（继承框架 `ServiceProvider`），并在 `config/app.php` 的 `providers` 数组里追加类名。框架内置 Provider 见 `src/Providers/`（Exception / Log / Cache / Event / Jwt / Validation / Limiting / Database / Queue / HttpClient / Messaging / Resilience / Translation / Http / Snowflake / Process / Console …）。

---

