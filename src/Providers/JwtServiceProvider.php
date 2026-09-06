<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Auth\JwtGuard;
use Kode\Framework\Contracts\AuthGuard;
use Kode\Framework\Providers\ServiceProvider;

/**
 * JWT 服务提供者（kode/jwt）
 */
final class JwtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(JwtGuard::class, function (): JwtGuard {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('jwt', []);

            return new JwtGuard($config);
        });

        // 以契约对外暴露：AuthMiddleware 只依赖 AuthGuard，不依赖具体 JWT 库。
        $this->container->alias(AuthGuard::class, JwtGuard::class);
        $this->container->alias('jwt', JwtGuard::class);
    }

    public function boot(): void
    {
        // 启动期可见性（非阻断）：HS 守卫已声明但 secret 为空时，鉴权接口首请求必 500。
        // 此处仅告警不断启动（无 JWT 的纯公开 API 应用同样要能启动），真正的 fail-fast
        // 由 JwtGuard 构造期完成——漏配者看到本告警即知去 .env 补 JWT_SECRET。
        $guards = (array) ($this->config('jwt.guards', []));
        foreach ($guards as $name => $guard) {
            if (!is_array($guard)) {
                continue;
            }
            $algo = strtoupper((string) ($guard['algo'] ?? 'HS256'));
            if (!str_starts_with($algo, 'HS')) {
                continue;
            }
            if (trim((string) ($guard['secret'] ?? '')) === '' && $this->container->has(\Psr\Log\LoggerInterface::class)) {
                $this->container->get(\Psr\Log\LoggerInterface::class)->warning(
                    "JWT secret 未配置：jwt.guards.{$name}.secret 为空，鉴权接口将在首次调用时失败。请在 .env 设置 JWT_SECRET。"
                );
            }
        }
    }
}
