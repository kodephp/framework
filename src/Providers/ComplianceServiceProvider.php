<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Http\Middleware\VersioningMiddleware;
use Kode\Framework\Security\Audit\AuditMiddleware;
use Kode\Framework\Security\Audit\AuditService;
use Kode\Http\App;
use Psr\Log\LoggerInterface;

/**
 * 合规与安全服务提供者（审计日志 + API 版本化）
 *
 *  - 注册 {@see AuditService} 单例（门面 Audit / 助手 audit()），并挂载审计中间件。
 *  - 挂载 API 版本化中间件（config/api.php 驱动）。
 *
 * 这两个能力都依赖 HttpApp，故本 Provider 在 HttpServiceProvider 之后 boot。
 */
final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(AuditService::class, function (): AuditService {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);

            return new AuditService($logger, (array) $this->config('audit', []));
        });
        $this->container->alias('audit', AuditService::class);
    }

    public function boot(): void
    {
        /** @var App $app */
        $app = $this->container->get(App::class);

        if (!empty($this->config('audit.enabled', true))) {
            /** @var AuditService $audit */
            $audit = $this->container->get(AuditService::class);
            $app->use(new AuditMiddleware($audit));
        }

        if (!empty($this->config('api.enabled', false))) {
            $app->use(new VersioningMiddleware((array) $this->config('api', [])));
        }
    }
}
