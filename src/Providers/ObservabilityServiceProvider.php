<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Observability\Metrics\MetricRegistry;
use Kode\Framework\Observability\Middleware\MetricsMiddleware;
use Kode\Framework\Observability\Middleware\TraceMiddleware;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Http\App;
use Kode\Http\Response;

/**
 * 可观测性服务提供者（指标 + 链路追踪）
 *
 *  - 注册 {@see MetricRegistry} 单例（门面 Metrics / 助手 metrics()）。
 *  - 挂载 TraceMiddleware（最外层，保证每个响应带 traceparent / X-Trace-Id）。
 *  - 挂载 MetricsMiddleware（自动采集 HTTP 吞吐 / 时延 / 错误率）。
 *  - 注册受保护的 /metrics 端点，供 Prometheus 抓取。
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(MetricRegistry::class, fn(): MetricRegistry => new MetricRegistry());
        $this->container->alias('metrics', MetricRegistry::class);
    }

    public function boot(): void
    {
        /** @var App $app */
        $app = $this->container->get(App::class);
        /** @var MetricRegistry $registry */
        $registry = $this->container->get(MetricRegistry::class);

        // ---- 链路追踪：最外层，保证每个响应都带链路头 ----
        if (!empty($this->config('observability.tracing.enabled', true))) {
            // 启动期注入 Tracer 单例（TracerServiceProvider 已 register），避免每请求 container resolve。
            // tracer() 关闭（enabled=false）时 TraceMiddleware 内部 isEnabled() 为 false，跳过 span 录制。
            $tracer = $this->container->bound(Tracer::class)
                ? $this->container->get(Tracer::class)
                : null;
            $app->getDispatcher()->prepend(new TraceMiddleware($tracer));
        }

        // ---- 自动请求指标 ----
        if (!empty($this->config('observability.metrics.enabled', true))) {
            $app->use(new MetricsMiddleware($registry, (array) $this->config('observability.metrics', [])));
            $this->registerMetricsEndpoint($app, $registry);
        }
    }

    /**
     * 注册受保护的 /metrics 端点。
     */
    private function registerMetricsEndpoint(App $app, MetricRegistry $registry): void
    {
        $path = (string) ($this->config('observability.metrics.path', '/metrics') ?: '/metrics');
        $protect = (string) ($this->config('observability.metrics.protect', 'token') ?: 'token');
        $token = (string) ($this->config('observability.metrics.token', '') ?? '');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            fwrite(STDERR, "[observability] /metrics 令牌（protect=token）：{$token}\n");
        }

        $app->get($path, function (\Psr\Http\Message\ServerRequestInterface $request) use ($registry, $protect, $token): Response {
            if (!$this->metricsAllowed($request, $protect, $token)) {
                return Response::make('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
            }

            return Response::make(
                $registry->render(),
                200,
                ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
            );
        });
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request
     */
    private function metricsAllowed(\Psr\Http\Message\ServerRequestInterface $request, string $protect, string $token): bool
    {
        if ($protect === 'none') {
            return true;
        }

        if ($protect === 'local') {
            $ip = $this->clientIp($request);

            return $ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, '127.');
        }

        // token（默认）：?token= 或 Authorization: Bearer
        $query = $request->getQueryParams();
        $given = $query['token'] ?? '';
        if (is_string($given) && $given !== '' && hash_equals($token, $given)) {
            return true;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && str_starts_with($auth, 'Bearer ')) {
            return hash_equals($token, substr($auth, 7));
        }

        return false;
    }

    private function clientIp(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        $fwd = $request->getHeaderLine('X-Forwarded-For');
        if ($fwd !== '') {
            return strtok($fwd, ',');
        }
        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return $real;
        }
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? 'unknown';
    }
}
