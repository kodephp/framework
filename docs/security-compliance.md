# 安全与合规

企业级安全基线：安全响应头、审计日志、API 版本化，开关集中在 `config/security.php`、`config/audit.php`、`config/api.php`。

## 一、安全响应头

`securityHeadersConfig()` 在响应上附加（部分来自 `config/security.php`）：

| 头 | 默认 | 说明 |
| --- | --- | --- |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` | 默认锁死，按业务放开 |
| `Permissions-Policy` | 禁用敏感特性 | 如 `geolocation=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` | 隔离跨源窗口 |
| `Cross-Origin-Resource-Policy` | `same-origin` | 防跨源资源加载 |
| `Cross-Origin-Embedder-Policy` | 关闭（默认 `false`） | 需跨源隔离时开启 |

```php
// config/security.php
return [
    'csp' => "default-src 'self'; img-src 'self' data:;", // 按前端需要放开
    'permissions_policy' => "geolocation=(), camera=()",
    'cross_origin_opener_policy' => 'same-origin',
    'cross_origin_resource_policy' => 'same-origin',
    'cross_origin_embedder_policy' => false, // true => require-corp
];
```

## 二、审计日志（Audit）

`AuditMiddleware` 包裹请求处理，在响应（或异常）后记录审计条目；经 kode/context 读取 `auth_user_id`（由 `AuthMiddleware` 写入），记录后**立即清除**，避免跨请求泄漏。

```php
// config/audit.php
return [
    'enabled'       => true,
    'ignore_paths'  => ['/health', '/metrics', '/ping'],
    'capture_user'  => true,   // 尝试捕获当前登录用户
    'log_level'     => 'info',
];
```

- 手动补记：`audit()->record($request, $response, $start, $userId);`
- 门面 `Audit` / 助手 `audit()`。

审计条目（默认走 PSR Logger）包含：方法、路径、状态码、耗时、用户 ID、忽略路径自动跳过。

## 三、API 版本化

`VersioningMiddleware` 对带 `/vN` 前缀的路由做版本校验（基础设施路径 `/health`、`/metrics`、`/docs`、`/ping` 跳过）：

```php
// config/api.php
return [
    'enabled'          => true,
    'prefix_required'  => false,                 // true => 必须带 /vN 前缀
    'current_version'  => 'v1',
    'supported_versions' => ['v1', 'v2'],
];
```

- 命中不支持的版本 → `404`；
- `prefix_required=true` 且无前缀 → `400`；
- 命中版本写入请求属性 `api_version`，供下游读取。

## 四、合规建议

- 生产务必配置 `csp`（最小权限），不要长期保留 `'none'` 之外的开放源；
- 审计 `ignore_paths` 排除探针类端点，避免噪声；
- API 对外演进时逐步引入 `v2` 并保持 `v1` 兼容期。
