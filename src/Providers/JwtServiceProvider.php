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
}
