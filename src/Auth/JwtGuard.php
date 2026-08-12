<?php

declare(strict_types=1);

namespace Kode\Framework\Auth;

use Kode\Framework\Contracts\AuthGuard;
use Kode\Jwt\KodeJwt;
use Kode\Jwt\Token\Payload;

/**
 * JWT 守卫（封装 kode/jwt）
 *
 * 实现 AuthGuard 契约，因此可被 AuthMiddleware 以契约方式依赖；
 * 后续更换鉴权方案只需把 AuthGuard::class 重新绑定到新实现，无需改动中间件。
 *
 * 对外暴露「业务友好」的 issue / authenticate / invalidate 方法；
 * 底层算法、密钥、TTL、黑名单等全部走 kode/jwt 的企业级能力。
 */
final class JwtGuard implements AuthGuard
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config 与 kode/jwt 配置结构对齐（guards.api.secret 必填）
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        KodeJwt::init($config);
    }

    /**
     * 签发令牌。claims 中的 uid/sub 等会被映射为标准 JWT 声明。
     *
     * 委托 kode/jwt 的守卫签发：每个守卫内部持有独立 Builder 实例，
     * 不会像共享单例那样冻结 jti / 累积 claims（旧实现会导致后续令牌
     * 复用首签 jti 并泄漏前次声明）。
     */
    public function issue(array $claims, string $guard = 'api'): string
    {
        $guardConfig = $this->guardConfig($guard);
        $ttl = (int) ($guardConfig['ttl'] ?? 3600);

        $payload = new Payload(
            uid: $claims['uid'] ?? null,
            username: $claims['username'] ?? null,
            platform: $claims['platform'] ?? $guardConfig['platform'] ?? 'default',
            exp: (int) ($claims['exp'] ?? time() + $ttl),
            iat: (int) ($claims['iat'] ?? time()),
            jti: $claims['jti'] ?? ('jwt_' . bin2hex(random_bytes(16))),
            roles: $claims['roles'] ?? null,
            perms: $claims['perms'] ?? null,
            custom: $claims['custom'] ?? [],
            nonce: $claims['nonce'] ?? null,
            audience: $claims['aud'] ?? $claims['audience'] ?? null,
            issuer: $claims['iss'] ?? $claims['issuer'] ?? null,
            subject: $claims['sub'] ?? $claims['subject'] ?? null,
        );

        $result = KodeJwt::guard($guard)->issue($payload);

        return (string) $result['token'];
    }

    /**
     * 校验令牌并返回载荷（失败抛异常）。
     *
     * 委托 kode/jwt 的 GuardInterface 实例（KodeJwt::guard）处理，
     * 框架只保留「claims 数组 → 签发」「令牌 → 校验」的薄适配与契约封装。
     */
    public function authenticate(string $token, string $guard = 'api'): Payload
    {
        return KodeJwt::guard($guard)->authenticate($token);
    }

    /**
     * 续期：用未过期的旧令牌换取新令牌（refresh_ttl 内有效）。
     */
    public function refresh(string $token, string $guard = 'api'): array
    {
        return KodeJwt::guard($guard)->refresh($token);
    }

    public function invalidate(string $token, string $guard = 'api'): bool
    {
        return KodeJwt::guard($guard)->invalidate($token);
    }

    /**
     * 按令牌把 jti 加入黑名单（即时失效，无需等过期）。
     */
    public function revokeToken(string $token, string $guard = 'api'): bool
    {
        return KodeJwt::revokeToken($token, $guard);
    }

    /**
     * 按 jti 把令牌加入黑名单（可指定存活秒数）。
     */
    public function revokeJti(string $jti, int $ttl = 3600, string $guard = 'api'): bool
    {
        return KodeJwt::revokeJti($jti, $ttl, $guard);
    }

    /**
     * 判断 jti 是否已在黑名单中。
     */
    public function isBlacklisted(string $jti, string $guard = 'api'): bool
    {
        return KodeJwt::isBlacklisted($jti, $guard);
    }

    /**
     * 把 jti 移出黑名单（恢复可用）。
     */
    public function unblacklist(string $jti, string $guard = 'api'): bool
    {
        return KodeJwt::unblacklist($jti, $guard);
    }

    /**
     * 撤销某用户在某平台下的全部令牌（SSO 强制下线）。
     */
    public function revokeUserTokens(string $uid, ?string $platform = null, string $guard = 'api'): int
    {
        return KodeJwt::revokeUserTokens($uid, $platform, $guard);
    }

    /**
     * 仅校验令牌结构/签名/黑名单，不抛异常，返回是否有效。
     */
    public function isTokenValid(string $token, string $guard = 'api'): bool
    {
        return KodeJwt::isTokenValid($token, $guard);
    }

    /**
     * 解析令牌元信息（jti / exp / 是否被黑名单等），不抛异常。
     *
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $token, string $guard = 'api'): ?array
    {
        return KodeJwt::getTokenInfo($token, $guard);
    }

    /**
     * @return array<string, mixed>
     */
    private function guardConfig(string $guard): array
    {
        return $this->config['guards'][$guard] ?? $this->config['guards']['api'] ?? [];
    }
}
