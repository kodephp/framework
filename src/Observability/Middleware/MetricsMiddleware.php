<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Middleware;

use Kode\Framework\Observability\Metrics\MetricRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 请求指标中间件（自动采集 HTTP 指标）
 *
 * 为每次请求记录：
 *  - `http_requests_total{method,route,code_class}` 计数（吞吐 + 错误率拆分）。
 *  - `http_request_duration_seconds{method,route}` 时延直方图（算 P50/P95/P99）。
 *
 * 默认跳过 /metrics 与 /health* / /ping 等基础设施端点，避免污染业务指标。
 * 业务也可手动用 {@see metrics()} 记录自定义指标（限流命中、熔断、队列积压等）。
 *
 * 放在异常中间件之内层，故能观测到 ExceptionMiddleware 转换后的最终状态码
 *（含 422 / 5xx），错误率统计准确。
 */
final class MetricsMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly array $config = [],
    ) {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($this->shouldSkip($path)) {
            return $handler->handle($request);
        }

        $start = microtime(true);
        $method = $request->getMethod();
        $route = $this->routeLabel($request);

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $this->record($method, $route, 500, $start);
            throw $e;
        }

        $this->record($method, $route, $response->getStatusCode(), $start);

        return $response;
    }

    private function record(string $method, string $route, int $status, float $start): void
    {
        $class = (int) floor($status / 100) . 'xx';

        // 计数（吞吐 + 错误率）100% 采集——原子自增，开销可忽略，保证总量/错误率准确。
        $this->registry
            ->counter('http_requests_total', 'HTTP 请求总数（按方法/路由/状态码类别）', ['method', 'route', 'code_class'])
            ->with(['method' => $method, 'route' => $route, 'code_class' => $class])
            ->inc();

        // 时延直方图按 sample_ratio 采样：observe() 维护高分辨率分位（HDR），是中间件中最贵的一步。
        // 采样后 P50/P95/P99 仍统计有效（标准 Prometheus 实践），每请求成本降约 1/sample_ratio 倍。
        // 计数保持 100% 故吞吐/错误率不受影响；需要精确时延分布时显式设 sample_ratio=1.0。
        $ratio = (float) ($this->config['sample_ratio'] ?? 0.1);
        if ($ratio >= 1.0 || lcg_value() < $ratio) {
            $elapsed = microtime(true) - $start;
            $this->registry
                ->histogram('http_request_duration_seconds', 'HTTP 请求时延分布（秒）', ['method', 'route'])
                ->with(['method' => $method, 'route' => $route])
                ->observe($elapsed);
        }
    }

    /**
     * 跳过基础设施端点（metrics / 健康检查 / ping）。
     */
    private function shouldSkip(string $path): bool
    {
        $skip = (array) ($this->config['skip_paths'] ?? [
            '/metrics', '/health', '/health/live', '/health/ready', '/ping',
        ]);

        return in_array($path, $skip, true);
    }

    /**
     * 路由标签：优先用路由名，否则用「数字段归一化」后的路径，避免高基数爆炸。
     */
    private function routeLabel(ServerRequestInterface $request): string
    {
        $name = $request->getAttribute('route_name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $path = $request->getUri()->getPath();
        if ($path === '') {
            return '/';
        }

        return preg_replace('#/\d+#', '/{id}', $path) ?? $path;
    }
}
