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
     * 续期：用未过期的旧令牌换取新令牌（refresh_ttl 内有效）。
     *
     * @return array<string, mixed>
     */
    public function refresh(string $token, string $guard = 'api'): array;

    /**
     * 注销（加入黑名单 / 使令牌失效）。
     */
    public function invalidate(string $token, string $guard = 'api'): bool;

    /**
     * 按令牌把 jti 加入黑名单（即时失效）。
     */
    public function revokeToken(string $token, string $guard = 'api'): bool;

    /**
     * 判断 jti 是否已在黑名单中。
     */
    public function isBlacklisted(string $jti, string $guard = 'api'): bool;

    /**
     * 把 jti 移出黑名单（恢复可用）。
     */
    public function unblacklist(string $jti, string $guard = 'api'): bool;
}
