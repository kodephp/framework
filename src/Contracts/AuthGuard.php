<?php

declare(strict_types=1);

namespace Kode\Framework\Contracts;

use Kode\Jwt\Token\Payload;

/**
 * 鉴权守卫契约。
 *
 * 框架默认实现为 kode/jwt 的 JwtGuard；引入此契约的目的是**解耦**：
 * AuthMiddleware 只依赖本契约，不依赖具体 JWT 库。后续若要更换鉴权方案
 * （例如换成另一套 JWT 实现、OAuth2、Session-Cookie 等），只需：
 *   1) 编写一个实现 AuthGuard 的类；
 *   2) 在 JwtServiceProvider（或你的 Provider）里把 AuthGuard::class 重新绑定到新类。
 * 控制器与中间件**无需任何改动**。
 */
interface AuthGuard
{
    /**
     * 签发令牌。
     *
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims, string $guard = 'api'): string;

    /**
     * 校验令牌，失败抛异常。
     */
    public function authenticate(string $token, string $guard = 'api'): Payload;

    /**
     * 注销（加入黑名单 / 使令牌失效）。
     */
    public function invalidate(string $token, string $guard = 'api'): bool;
}
