# 异常处理

- 全局 `ExceptionMiddleware` 接管所有未捕获异常，转成结构化 JSON（见入门指南 §7）。
- 业务里直接抛异常即可：框架负责格式，你只管抛。

```php
if ($user === null) {
    throw new \RuntimeException('用户不存在');   // → 500 {"message":"用户不存在", ...}
}
```

- 校验异常 → 422（含 `errors`）；其它 → 500；404 / 405 / 401 / 429 自动映射。
- 想拿异常管理器：`exception_manager()` 助手或 `Exception` 门面。

---

