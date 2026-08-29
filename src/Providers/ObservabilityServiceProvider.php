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
            $app->getDispatcher()->prepend(new TraceMiddleware($tracer, (array) $this->config('observability.tracing', [])));
        }

        // ---- 自动请求指标 ----
        if (!empty($this->config('observability.metrics.enabled', true))) {
            // 可关闭「挂载请求指标中间件」（保留 registry / 端点 / 门面）。
            // 供压测隔离：区分「中间件每请求成本」与「observability 组其余启动副作用」。
            if (!empty($this->config('observability.metrics.middleware_enabled', true))) {
                $app->use(new MetricsMiddleware($registry, (array) $this->config('observability.metrics', [])));
            }
            // 指标抓取端点默认注册；纯内网旁路采集（独立 metric agent 定时 scrape /
            // 网关统一抓取）时可关闭，避免业务路由表多一行匹配。
            if (!empty($this->config('observability.metrics.register_endpoint', true))) {
                $this->registerMetricsEndpoint($app, $registry);
            }
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
            $ip = $this->remoteAddr($request);

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

    private function remoteAddr(\Psr\Http\Message\ServerRequestInterface $request): string
    {
        // protect=local 只信对端地址（v1.0.52 安全修复）：X-Forwarded-For / X-Real-IP 可被
        // 任意客户端伪造，旧实现远程攻击者带 'X-Forwarded-For: 127.0.0.1' 即可绕过本机限制。
        // 经反向代理部署时请改用 token 模式或在网络层隔离 /metrics、/docs。
        $server = $request->getServerParams();

        return $server['REMOTE_ADDR'] ?? '';
    }
}
