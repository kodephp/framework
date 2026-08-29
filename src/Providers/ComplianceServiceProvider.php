<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Http\Middleware\VersioningMiddleware;
use Kode\Framework\Security\Audit\AuditMiddleware;
use Kode\Framework\Security\Audit\AuditService;
use Kode\Framework\Security\Audit\AuditSink;
use Kode\Framework\Server\GracefulShutdown;
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
        $this->container->singleton(AuditSink::class, static fn(): AuditSink => new AuditSink());

        $this->container->singleton(AuditService::class, function (): AuditService {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);
            $sink = $this->container->bound(AuditSink::class)
                ? $this->container->get(AuditSink::class)
                : null;
            $async = (bool) ($this->config('audit.async', true) ?? true);

            return new AuditService($logger, (array) $this->config('audit', []), $sink, $async, $this->trustedProxies());
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

            // 离路径 flush 钩子：审计默认异步入队（v1.0.0，与访问日志同范式），
            // 真实写入由响应后的 shutdown / 优雅停机批量执行，不阻塞客户端响应。
            $async = (bool) ($this->config('audit.async', true) ?? true);
            if ($async && $this->container->bound(AuditSink::class)) {
                $this->registerAuditFlush();
            }
        }

        if (!empty($this->config('api.enabled', false))) {
            $app->use(new VersioningMiddleware((array) $this->config('api', [])));
        }
    }

    /**
     * 注册审计队列的离路径 flush（对称 LogServiceProvider 的访问日志钩子）。
     *
     *  - Swoole / Workerman：注册优雅停机 cleanup（worker 退出前 flush，避免丢失）。
     *  - FPM / CLI：register_shutdown_function（响应发出之后执行）。
     */
    private function registerAuditFlush(): void
    {
        if ($this->container->bound(GracefulShutdown::class)) {
            /** @var GracefulShutdown $graceful */
            $graceful = $this->container->get(GracefulShutdown::class);
            $graceful->registerCleanup(static function (): void {
                try {
                    resolve(AuditSink::class)->flush(resolve(LoggerInterface::class));
                } catch (\Throwable) {
                    // flush 失败不影响停机
                }
            });
        }

        register_shutdown_function(static function (): void {
            try {
                resolve(AuditSink::class)->flush(resolve(LoggerInterface::class));
            } catch (\Throwable) {
                // flush 失败不影响主流程
            }
        });
    }
}
