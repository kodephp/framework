# CSRF 防护

框架内置 CSRF（跨站请求伪造）防护，采用「**按需挂载**」立场：只有显式标记的路由才触发令牌校验，
其余路由（含 `/ping`、纯 JWT 接口）在全局中间件里走 O(1) 早退，零可感知开销——
故「加上企业级中间件也不影响响应」。

## 一、机制概览

- 令牌存于**会话**（cookie-session 形态），采用标准 double-submit 校验。
- 安全方法（`GET/HEAD/OPTIONS`）在被标记路由上首次访问时签发令牌，并通过
  响应头 `X-CSRF-Token` 回传，供 SPA / 表单引导。
- 非安全方法（`POST/PUT/PATCH/DELETE`）校验以下任一来源提交的令牌：
  - 请求头 `X-CSRF-Token`（默认）/`X-XSRF-Token`
  - 表单 / JSON 体字段 `_token`
  - 查询参数 `_token`
  与会话令牌 `hash_equals` 一致才放行，否则 `419`。
- **无会话**的应用（纯 JWT 无 cookie 接口）即便被标记也安全跳过——CSRF 仅对
  cookie-session 形态的会话劫持有效。

## 二、声明式标记

控制器类或方法上加 `#[Csrf]` 即可纳入防护：

```php
use Kode\Framework\Security\Csrf\Csrf;

// 整个控制器纳入防护（cookie-session Web 应用典型用法）
#[Csrf]
class ProfileController extends Controller
{
    public function update(): Response { /* ... */ }
}

// 仅个别写操作纳入防护
class ApiController extends Controller
{
    #[Csrf]
    public function delete(): Response { /* ... */ }
}
```

也支持「自动对全部非安全路由套用」（默认关，推荐用 `#[Csrf]` 精确标记）：

```php
// config/csrf.php
return [
    'auto_apply_unsafe' => false, // 默认关；true 时所有 POST/PUT/PATCH/DELETE 都校验
    'exclude_paths'     => ['/health', '/metrics', '/ping'], // 自动模式下的排除
    'skip_paths'        => [],   // 即便被 #[Csrf] 标记也豁免（Webhook 等需跨站调用）
];
```

## 三、前端如何取令牌 / 提交

**SPA（fetch / axios）**：从任意被标记 `GET` 响应的 `X-CSRF-Token` 头读取，随写请求带回报文头：

```js
// 读取
const token = resp.headers.get('X-CSRF-Token');
// 提交
fetch('/profile/update', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
  body: JSON.stringify(payload),
});
```

**服务端模板（表单）**：用助手在表单里嵌入隐藏域，或取令牌写进 meta：

```php
<form method="post" action="/profile/update">
    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
    ...
</form>

// 或取令牌（无会话时返回 null）
$metaToken = csrf_token();
```

> `csrf_token()` 缺令牌时惰性补签；`csrf_token_rotate()` 用于下文「会话固定防护」。

## 四、会话固定防护（登录后轮换令牌）

会话固定攻击中，攻击者先取得一个合法会话并诱使用户登录，借固定会话冒充用户。
登录成功、身份确立后应立即**轮换 CSRF 令牌**，使登录前的固定令牌立即作废：

```php
// 登录处理末尾
auth()->attempt($credentials);  // 建立会话
csrf_token_rotate();            // 轮换 CSRF 令牌，作废登录前的固定令牌
```

`csrf_token_rotate()`：
- 无会话（纯 JWT / CLI）时返回 `null`，不产生副作用；
- 否则重新生成并写回会话令牌，旧令牌立即失效。

## 五、安全可观测性（csrf.failed 审计事件）

校验失败时，框架经**离路径异步审计管线**记录 `csrf.failed` 安全事件（与 `auth.failed` 同源，
SOC 可统一订阅告警）。该事件仅在失败时触发，不污染正常请求热路径；审计未启用则静默跳过。

事件结构（进入 `audit` 日志流）：

```json
{
  "event": "csrf.failed",
  "detail": { "reason": "missing_token | token_mismatch", "method": "POST", "path": "/profile/update" },
  "user_id": null,
  "client_ip": "203.0.113.7",
  "request_id": "…",
  "user_agent": "…",
  "referer": "…"
}
```

行为开关（`config/csrf.php`）：

```php
return [
    'audit_on_failure' => true,        // 失败时记录 csrf.failed（默认开）
    'audit_action'     => 'csrf.failed', // 事件名，可被 SIEM 规则覆盖
];
```

## 六、完整配置

```php
// config/csrf.php
return [
    'enabled'           => true,   // 全局中间件总开关（默认开，对无关路由零开销）
    'token_key'         => '_csrf_token',
    'header'            => 'X-CSRF-Token',
    'xsrf_header'       => 'X-XSRF-Token',
    'token_param'       => '_token',
    'error_status'      => 419,
    'error_message'     => 'CSRF token mismatch',
    'auto_apply_unsafe' => false,
    'exclude_paths'     => ['/health', '/metrics', '/ping', '/favicon.ico'],
    'skip_paths'        => [],
    'audit_on_failure'  => true,
    'audit_action'      => 'csrf.failed',
];
```

## 七、性能立场

CSRF 是全局中间件，但对未标记路由只做「一次路由匹配 + 一次哈希查表」即早退，
无可感知开销。校验失败时记录审计事件同样走离路径异步导出（µs 级内存入队，响应后批量落盘），
绝不阻塞客户端响应。进程隔离压测下，开启 CSRF + 审计对企业级业务端点的影响 ≈0
（审计税 <3%、框架税 ≈57%，与未加 CSRF 时一致）。
