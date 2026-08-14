<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Logging\AccessLogSink;
use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Server\GracefulShutdown;
use Kode\Framework\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * 日志服务提供者（Monolog，遵循 PSR-3）
 *
 *  - 绑定 {@see LoggerInterface} 单例（助手 logger() / 门面 Log）。
 *  - 绑定 {@see AccessLogSink} 单例：访问日志的离路径导出队列（与 Tracer 的 outbox 同范式）。
 *  - 访问日志默认异步（入队后由响应后钩子批量落盘）；async=false 时中间件退化为同步写。
 */
final class LogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LoggerInterface::class, function (): LoggerInterface {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('logging', []);

            return LoggerFactory::create($config);
        });

        // 'log' 作为 LoggerInterface 的别名，便于 resolve('log')。
        $this->container->alias('log', LoggerInterface::class);

        // 访问日志离路径导出队列（进程级静态队列，对称 Tracer 的 outbox）。
        $this->container->singleton(AccessLogSink::class, static fn (): AccessLogSink => new AccessLogSink());
        $this->container->alias('accessLogSink', AccessLogSink::class);
    }

    public function boot(): void
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) $this->config('logging.access_log', []);
        if (empty($cfg['enabled'] ?? true)) {
            return; // 访问日志关闭，无需离路径 flush
        }

        if (empty($cfg['async'] ?? true)) {
            return; // 同步模式：中间件直接写 logger，无需离路径 flush
        }

        // 离请求路径落盘：与追踪同范式，把队列批量写入 logger，绝不阻塞客户端响应。
        //  - Swoole / Workerman：注册优雅停机 cleanup（worker 退出前 flush，避免丢失）。
        //  - FPM / CLI：注册 shutdown 钩子（响应已发出之后）。
        if ($this->container->bound(GracefulShutdown::class)) {
            /** @var GracefulShutdown $graceful */
            $graceful = $this->container->get(GracefulShutdown::class);
            $graceful->registerCleanup(static function (): void {
                try {
                    resolve(AccessLogSink::class)->flush(resolve(LoggerInterface::class));
                } catch (\Throwable) {
                    // flush 失败不影响停机
                }
            });
        }

        register_shutdown_function(static function (): void {
            try {
                resolve(AccessLogSink::class)->flush(resolve(LoggerInterface::class));
            } catch (\Throwable) {
                // flush 失败不影响主流程
            }
        });
    }
}
