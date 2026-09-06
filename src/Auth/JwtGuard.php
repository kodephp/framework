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
     *
     * @throws \RuntimeException HS* 算法守卫的 secret 为空时（启动期 fail-fast，
     *                           防止以公开可知的空/默认密钥签发可被任意伪造的令牌）
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->assertSecretConfigured($config);
        KodeJwt::init($config);
    }

    /**
     * 校验 HMAC 类算法守卫必须配置非空 secret（kode/jwt 的 Parser 会拒绝空密钥验签，
     * 但签发侧若无此防线，漏配 JWT_SECRET 的应用会在运行期才暴露、且报错误导排障方向）。
     *
     * @param array<string, mixed> $config
     */
    private function assertSecretConfigured(array $config): void
    {
        $guards = $config['guards'] ?? [];
        if (!is_array($guards)) {
            return;
        }

        foreach ($guards as $name => $guard) {
            if (!is_array($guard)) {
                continue;
            }
            $algo = strtoupper((string) ($guard['algo'] ?? 'HS256'));
            if (!str_starts_with($algo, 'HS')) {
                continue; // 非 HMAC 算法（RS*/ES* 等）不依赖共享 secret
            }
            $secret = $guard['secret'] ?? '';
            if (!is_string($secret) || trim($secret) === '') {
                throw new \RuntimeException(
                    "JWT secret 未配置：guards.{$name}.secret 不能为空（HS{$algo} 需要非空共享密钥）。"
                    . '请在 .env 设置 JWT_SECRET 或调整 config/jwt.php。'
                );
            }
            $trimmed = trim($secret);
            $isProd = (($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'local') === 'production')
                || (getenv('APP_ENV') === 'production');
            // 拒绝公开占位符：仅在 production 强制（本地/测试允许短密钥如 test-secret 以便回归），
            // 但空串在所有环境均拒绝（上一分支已处理）。
            if ($isProd) {
                $lower = strtolower($trimmed);
                $weakPatterns = ['change-me', 'placeholder', 'your-secret', 'kode-framework-secret', '__generated_on_init__'];
                foreach ($weakPatterns as $pat) {
                    if (str_contains($lower, $pat)) {
                        throw new \RuntimeException(
                            "JWT secret 过于简单：guards.{$name}.secret 命中弱密钥模式 '{$pat}'，请生成强随机密钥（bin2hex(random_bytes(32))）。"
                        );
                    }
                }
                if (strlen($trimmed) < 32) {
                    throw new \RuntimeException(
                        "JWT secret 过短：guards.{$name}.secret 长度 " . strlen($trimmed) . " < 32，请使用至少 32 字符的强随机密钥。"
                    );
                }
            }
        }
    }

    /**
     * 签发令牌。claims 中的 uid/sub 等会被映射为标准 JWT 声明。
     *
     * 委托 kode/jwt 的守卫签发：每个守卫内部持有独立 Builder 实例，
     * 不会像共享单例那样冻结 jti / 累积 claims（旧实现会导致后续令牌
     * 复用首签 jti 并泄漏前次声明）。
     *
     * 有效期钳制：exp 恒不超过 iat + 守卫 ttl——调用方传入的超长 exp 会被
     * 压到上限（需更长有效期请调高 ttl，而非逐次传参）；iat 缺省为当前时间。
     */
    public function issue(array $claims, string $guard = 'api'): string
    {
        $guardConfig = $this->guardConfig($guard);
        $ttl = (int) ($guardConfig['ttl'] ?? 3600);

        $iat = (int) ($claims['iat'] ?? time());
        $exp = (int) ($claims['exp'] ?? ($iat + $ttl));
        if ($exp > $iat + $ttl) {
            $exp = $iat + $ttl;
        }

        $payload = new Payload(
            uid: $claims['uid'] ?? null,
            username: $claims['username'] ?? null,
            platform: $claims['platform'] ?? $guardConfig['platform'] ?? 'default',
            exp: $exp,
            iat: $iat,
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
     *
     * @throws \InvalidArgumentException 守卫未在 config 中声明时（旧实现静默回落 guards.api
     *                                   计算 TTL，而 KodeJwt::guard 却以空配置构建真实签发器，
     *                                   会签出「exp 正确但验签永不能通过」的废令牌）
     */
    private function guardConfig(string $guard): array
    {
        $guards = is_array($this->config['guards'] ?? null) ? $this->config['guards'] : [];
        if (!isset($guards[$guard]) || !is_array($guards[$guard])) {
            throw new \InvalidArgumentException(
                "未知 JWT 守卫：{$guard}（请在 config/jwt.php 的 guards 中声明）"
            );
        }

        return $guards[$guard];
    }
}
