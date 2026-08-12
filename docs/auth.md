# 鉴权（JWT）

### 9.1 签发令牌（登录接口示例）

```php
public function login(): array
{
    $data = $this->validate($this->params(), [
        'username' => 'required',
        'password' => 'required',
    ]);

    // 真实项目在此校验密码；这里仅演示签发
    $token = jwt()->issue([
        'uid'   => 1,
        'sub'   => 'u1',
        'roles' => ['user'],
    ]);

    return $this->json(['token' => $token]);
}
```

`jwt()->issue($claims)` 委托 `kode/jwt` 守卫签发；每次签发都是独立实例，jti 唯一、不会泄漏前次声明。

### 9.2 保护路由

```php
$app->get('/me', fn() => resolve(UserController::class)->me())
    ->middleware(new \Kode\Framework\Http\Middleware\AuthMiddleware());
```

`AuthMiddleware` 从 `Authorization: Bearer <token>` 解析，失败返回 401，成功把载荷挂到请求属性 `auth`。

### 9.3 在控制器里取当前用户

```php
public function me(): array
{
    /** @var \Kode\Jwt\Token\Payload $auth */
    $auth = $this->request()->getAttribute('auth');
    return ['uid' => (string) $auth->uid];
}
```

也可手动校验：`$payload = jwt()->authenticate($token);`，注销：`jwt()->invalidate($token)`。

> 契约解耦：控制器/中间件只依赖 `Kode\Framework\Contracts\AuthGuard`，更换鉴权方案
> （换 JWT 算法、改 OAuth2、切 Session-Cookie）只需在 `JwtServiceProvider` 重新绑定，业务代码零改动。

### 9.4 续期与黑名单（kode/jwt 1.12+）

```php
// 续期：用未过期的旧令牌换取新令牌（refresh_ttl 内有效）
$next = jwt()->refresh($oldToken);          // ['token' => '...', 'exp' => ...]

// 即时失效：把某令牌（或 jti）加入黑名单
jwt()->revokeToken($token);                 // 按令牌
jwt()->revokeJti($jti, 600);                // 按 jti，指定存活秒数
jwt()->isBlacklisted($jti);                  // 是否已在黑名单
jwt()->unblacklist($jti);                    // 移出黑名单（恢复可用）

// 强制下线：撤销某用户在某平台下的全部令牌（SSO）
jwt()->revokeUserTokens($uid, 'web');
```

底层走 `kode/jwt` 的存储黑名单（memory / redis …），`config/jwt.php` 里 `blacklist_enabled`
与 `blacklist_ttl` 控制行为；`isTokenValid($token)` 可在不抛异常的情况下判断令牌是否有效。

---

