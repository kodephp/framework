<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Observability\Metrics\MetricRegistry;
use Kode\Framework\Observability\Trace\TraceContext;
use Kode\Framework\Testing\TestCase;
use Nyholm\Psr7\ServerRequest;

/**
 * 可观测性测试：指标注册表 + 链路上下文 + /metrics 端点（保护与内容）。
 */
final class ObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 固定令牌，便于在测试中验证 /metrics 保护策略。
        // 注意：生效的 env() 来自 kode/fibers，仅读 getenv()，故用 putenv 注入。
        putenv('OBS_METRICS_PROTECT=token');
        putenv('OBS_METRICS_TOKEN=test-token');
        // 测试内强制全采样，避免 sample_ratio 让直方图时延序列出现概率性缺失。
        putenv('OBS_METRICS_SAMPLE_RATIO=1.0');
        $this->bootApp(getcwd());
    }

    // ------------------------------------------------------------------
    // 指标注册表（纯单元）
    // ------------------------------------------------------------------

    public function testCounterRendersPrometheusFormat(): void
    {
        $r = new MetricRegistry();
        $r->counter('http_requests_total', '请求总数', ['method', 'code_class'])
            ->with(['method' => 'GET', 'code_class' => '2xx'])->inc();
        $r->counter('http_requests_total', '请求总数', ['method', 'code_class'])
            ->with(['method' => 'GET', 'code_class' => '2xx'])->inc(2);

        $text = $r->render();
        self::assertStringContainsString('# TYPE http_requests_total counter', $text);
        self::assertStringContainsString('http_requests_total{method="GET",code_class="2xx"} 3', $text);
    }

    public function testGaugeSetAndDec(): void
    {
        $r = new MetricRegistry();
        $g = $r->gauge('queue_size', '队列积压', ['name']);
        $g->with(['name' => 'mail'])->set(10);
        $g->with(['name' => 'mail'])->dec(3);

        $text = $r->render();
        self::assertStringContainsString('# TYPE queue_size gauge', $text);
        self::assertStringContainsString('queue_size{name="mail"} 7', $text);
    }

    public function testHistogramBucketsAndSum(): void
    {
        $r = new MetricRegistry();
        $h = $r->histogram('http_duration_seconds', '时延', []);
        $h->observe(0.01);
        $h->observe(2.0);

        $text = $r->render();
        self::assertStringContainsString('# TYPE http_duration_seconds histogram', $text);
        self::assertStringContainsString('http_duration_seconds_sum{} 2.01', $text);
        self::assertStringContainsString('http_duration_seconds_count{} 2', $text);
        // 0.01 <= 0.025 桶累计 1；<= 2.5 桶累计 2。
        self::assertStringContainsString('http_duration_seconds_bucket{le="0.025"} 1', $text);
        self::assertStringContainsString('http_duration_seconds_bucket{le="2.5"} 2', $text);
    }

    // ------------------------------------------------------------------
    // 链路上下文（纯单元）
    // ------------------------------------------------------------------

    public function testTraceGeneratedWhenAbsent(): void
    {
        Context::clear();
        $req = new ServerRequest('GET', '/x');
        TraceContext::ensure($req);

        $id = TraceContext::traceId();
        self::assertNotNull($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
        self::assertNotNull(TraceContext::spanId());
    }

    public function testTraceReusesTraceparent(): void
    {
        Context::clear();
        $req = new ServerRequest('GET', '/x', ['traceparent' => '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01']);
        TraceContext::ensure($req);

        self::assertSame(str_repeat('a', 32), TraceContext::traceId());
        self::assertSame(str_repeat('b', 16), TraceContext::spanId());
    }

    public function testResponseHeadersIncludeTraceparent(): void
    {
        Context::clear();
        TraceContext::ensure(new ServerRequest('GET', '/x'));
        $headers = TraceContext::responseHeaders();

        self::assertArrayHasKey('traceparent', $headers);
        self::assertArrayHasKey('X-Trace-Id', $headers);
        self::assertArrayHasKey('X-Span-Id', $headers);
        self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/', $headers['traceparent']);
    }

    // ------------------------------------------------------------------
    // 集成：真实请求 + /metrics 端点
    // ------------------------------------------------------------------

    public function testResponseCarriesTraceHeaders(): void
    {
        $res = $this->get('/health');
        $res->assertStatus(200);
        self::assertNotEmpty($res->header('X-Trace-Id'));
        self::assertNotEmpty($res->header('traceparent'));
    }

    public function testMetricsEndpointForbiddenWithoutToken(): void
    {
        $this->get('/metrics')->assertStatus(403);
    }

    public function testMetricsEndpointAllowedWithToken(): void
    {
        // 先制造一条业务请求（非 skip 路径，会被指标中间件采集）。
        $this->get('/__nonexistent_for_metrics__')->assertStatus(404);

        $res = $this->get('/metrics?token=test-token');
        $res->assertStatus(200);
        $body = $res->body();
        self::assertStringContainsString('# TYPE http_requests_total counter', $body);
        self::assertStringContainsString('http_requests_total{method="GET",route="/__nonexistent_for_metrics__",code_class="4xx"}', $body);
        self::assertStringContainsString('# TYPE http_request_duration_seconds histogram', $body);
    }

    public function testMetricsEndpointAllowedWithBearer(): void
    {
        $this->get('/metrics', ['Authorization' => 'Bearer test-token'])->assertStatus(200);
    }
}
