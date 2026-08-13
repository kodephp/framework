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

        // worker 优雅停机时 flush 待导出 span（避免链路在退出时丢失）
        if ($this->container->bound(GracefulShutdown::class)) {
            /** @var GracefulShutdown $graceful */
            $graceful = $this->container->get(GracefulShutdown::class);
            $graceful->registerCleanup(static function (): void {
                try {
                    resolve(Tracer::class)->flush();
                } catch (\Throwable) {
                    // flush 失败不影响停机
                }
            });
        }
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
