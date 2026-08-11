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
     * 签发令牌。claims 中的 uid/sub 会被同时写入标准声明。
     */
    public function issue(array $claims, string $guard = 'api'): string
    {
        $guardConfig = $this->guardConfig($guard);

        $builder = KodeJwt::builder();
        $builder->setClaims($claims);

        if (isset($claims['uid'])) {
            $builder->setUid($claims['uid']);
        }
        if (isset($claims['sub'])) {
            $builder->setSubject($claims['sub']);
        }

        // SSO 守卫要求必须携带 platform 声明；缺省时从 guard 配置读取。
        $platform = $claims['platform'] ?? $guardConfig['platform'] ?? null;
        if ($platform !== null && $platform !== '') {
            $builder->setPlatform((string) $platform);
        }

        $ttl = (int) ($guardConfig['ttl'] ?? 3600);
        $builder->setIssuedAt(time())->setExpiration(time() + $ttl);

        return $builder->build();
    }

    /**
     * 校验令牌并返回载荷（失败抛异常）。
     */
    public function authenticate(string $token, string $guard = 'api'): Payload
    {
        return KodeJwt::authenticate($token, $guard);
    }

    public function invalidate(string $token, string $guard = 'api'): bool
    {
        return KodeJwt::invalidate($token, $guard);
    }

    /**
     * @return array<string, mixed>
     */
    private function guardConfig(string $guard): array
    {
        return $this->config['guards'][$guard] ?? $this->config['guards']['api'] ?? [];
    }
}
