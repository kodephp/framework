# 跨域 CORS 与安全响应头

默认开启 CORS 与安全头（见 `config/cors.php`、`config/security.php`）：

- `config/cors.php`：允许的 origins / methods / headers / 是否带凭证。
- `config/security.php`：默认注入 `X-Content-Type-Options`、`X-Frame-Options`、
  `Content-Security-Policy` 等（开关 `enabled`）。

需要自定义响应头时，在控制器里照常 `withHeader('X-Custom', $v)` 即可，安全头会在管线末端补上。





---

