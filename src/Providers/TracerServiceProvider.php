<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Application;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\Exporters\FileSpanExporter;
use Kode\Framework\Observability\Trace\Exporters\OtlpHttpExporter;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Framework\Server\GracefulShutdown;

/**
 * 分布式追踪服务提供者（OTLP 导出）。
 *
 *  - 绑定 {@see Tracer} 单例（助手 tracer() / 门面 Tracer）。
 *  - 按配置选择导出器（otlp_http / file / 自定义 SpanExporter）注入容器。
 *  - 监听优雅停机：worker 退出前 flush 待导出 span，避免链路丢失。
 *
 * 设计立场：不直接构建 exporter 的副作用放在 boot；若 tracing 关闭则完全不接线，
 * tracer() 返回 no-op Tracer，不产生任何 span——与配置中心、服务发现同哲学。
 */
final class TracerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 追踪总开关关闭时，一并关闭 kode/http 的入站链路头同步（每请求省 4× 头嗅探）：
        // Tracer / TraceMiddleware / 日志关联均不依赖此次同步（各自独立），RequestId
        // 中间件直接读请求头亦不受影响。注意这是进程级静态开关，每次引导按本进程配置重设。
        $tracingEnabled = !empty($this->config('observability.tracing.enabled', true));
        if (method_exists(\Kode\Http\Request::class, 'setTraceSyncEnabled')) {
            \Kode\Http\Request::setTraceSyncEnabled($tracingEnabled);
        }

        $this->container->singleton(Tracer::class, function (): Tracer {
            /** @var array<string, mixed> $cfg */
            $cfg = (array) $this->config('observability.tracing', []);
            $enabled = !empty($cfg['enabled'] ?? true);

            return new Tracer(
                $enabled,
                $cfg,
                fn (object $event): object => event($event),
                fn (): ?SpanExporter => $this->container->bound(SpanExporter::class)
                    ? $this->container->get(SpanExporter::class)
                    : null,
                // 默认异步导出：请求路径仅内存入队，不阻塞响应。
                (bool) ($cfg['async'] ?? true),
            );
        });
        $this->container->alias('tracer', Tracer::class);
    }

    public function boot(): void
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) $this->config('observability.tracing', []);
        if (empty($cfg['enabled'] ?? true)) {
            return;
        }

        $exporter = $this->makeExporter($cfg);
        if ($exporter !== null) {
            $this->container->singleton(SpanExporter::class, static fn (): SpanExporter => $exporter);
            $this->container->alias('spanExporter', SpanExporter::class);
        }

        // 离请求路径导出：把进程级 outbox 批量发送，绝不阻塞客户端响应。
        //  - Swoole / Workerman：注册周期性 tick 定时器（常驻进程最优，请求路径零网络开销）。
        //  - FPM / CLI：注册 shutdown 钩子，响应发出后再 drain（客户端已收到响应）。
        $this->registerOffPathDrain();

        // worker 优雅停机时 drain 待导出 span（避免链路在退出时丢失）
        if ($this->container->bound(GracefulShutdown::class)) {
            /** @var GracefulShutdown $graceful */
            $graceful = $this->container->get(GracefulShutdown::class);
            $graceful->registerCleanup(static function (): void {
                try {
                    resolve(Tracer::class)->drain();
                } catch (\Throwable) {
                    // drain 失败不影响停机
                }
            });
        }
    }

    /**
     * 注册离请求路径的 span 导出时机。
     *
     * 请求路径上的 {@see Tracer::enqueueFlush()} 只做内存入队（µs 级），真实网络发送
     * 必须离请求路径执行，否则会像 SimpleSpanProcessor 那样每请求同步阻塞 OTLP POST
     * 拖垮吞吐。默认在响应发出后的 shutdown 阶段 drain（FPM / CLI / worker 退出均适用，
     * 客户端已收到响应、不感知延迟）。
     *
     * 常驻进程（Swoole / Workerman）如需在进程存活期内持续导出、避免 outbox 堆积，
     * 应在服务启动后调用 {@see Tracer::drain()} 注册周期性 tick 定时器——框架无法在
     * provider boot 阶段可靠判断当前是否处于运行中的事件循环（Swoole 扩展在纯 CLI 下
     * 亦加载，盲目注册定时器会在 shutdown 触发 Event::wait 永久挂起），故交由应用显式注册。
     */
    private function registerOffPathDrain(): void
    {
        register_shutdown_function(static function (): void {
            try {
                resolve(Tracer::class)->drain();
            } catch (\Throwable) {
                // drain 失败不影响主流程
            }
        });
    }

    /**
     * 按配置构建导出器实例。
     */
    private function makeExporter(array $cfg): ?SpanExporter
    {
        $kind = (string) ($cfg['exporter'] ?? 'otlp_http');

        if ($kind === 'otlp_http') {
            /** @var array<string, mixed> $otlp */
            $otlp = (array) ($cfg['otlp'] ?? []);
            /** @var array<string, string> $headers */
            $headers = (array) ($otlp['headers'] ?? []);

            return new OtlpHttpExporter(
                (string) ($otlp['endpoint'] ?? 'http://localhost:4318/v1/traces'),
                $headers,
                (int) ($otlp['timeout'] ?? 2),
                $this->resourceAttributes($cfg),
            );
        }

        if ($kind === 'file') {
            /** @var array<string, mixed> $file */
            $file = (array) ($cfg['file'] ?? []);

            return new FileSpanExporter(
                (string) ($file['path'] ?? (sys_get_temp_dir() . '/kode-traces.ndjson'))
            );
        }

        // 自定义导出器（实现 SpanExporter 的类名）
        if ($kind !== '' && class_exists($kind)) {
            $instance = new $kind();
            if ($instance instanceof SpanExporter) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    private function resourceAttributes(array $cfg): array
    {
        return [
            'service.name' => (string) ($cfg['service_name'] ?? 'kode-app'),
            'service.version' => Application::VERSION,
            'telemetry.sdk.name' => 'kode/framework',
        ];
    }
}
