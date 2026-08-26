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
        // 常驻进程（Workerman）周期离路径落盘：访问日志按固定间隔批量写 logger，
        // 请求线程零 I/O。仅当确实处于 Workerman 运行环境时注册（其 Timer::add 在
        // 非运行环境会抛异常，静默降级到下方停机/shutdown 钩子兜底）；Swoole / Native
        // 场景与 Tracer 同哲学：无法在 boot 阶段可靠判断事件循环，交由应用显式注册
        // 定时器（或依赖停机钩子）。
        // 间隔可配 access_log.flush_interval（秒）；默认 1s。队列有界性由
        // AccessLogSink::MAX（8192）兜底，极端过载丢新日志、不阻塞请求、不 OOM。
        // 每次 tick 最多导出一批（access_log.flush_batch，默认 1024）——高并发下
        // 单次全量 flush（数千条 Monolog 写盘）会让事件循环线程出现秒级延迟尖峰，
        // 分批发（余量留给下一 tick）把写盘 I/O 摊薄，保证周期性停顿不可感知。
        $interval = (float) ($cfg['flush_interval'] ?? 1.0);
        $batch    = (int) ($cfg['flush_batch'] ?? 1024);
        if (class_exists(\Workerman\Timer::class)) {
            try {
                \Workerman\Timer::add($interval > 0 ? $interval : 1.0, static function () use ($batch): void {
                    try {
                        resolve(AccessLogSink::class)->flush(resolve(LoggerInterface::class), $batch);
                    } catch (\Throwable) {
                        // flush 失败不影响运行时
                    }
                });
            } catch (\Throwable) {
                // 非 Workerman 常驻环境（CLI / FPM / Swoole）：不注册周期定时器，
                // 由下方优雅停机 / shutdown 钩子兜底，避免盲目注册导致 shutdown 挂起。
            }
        }

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
