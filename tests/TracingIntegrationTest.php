<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\Span;
use Kode\Framework\Observability\Trace\SpanKind;
use Kode\Framework\Observability\Trace\Tracer;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 分布式追踪集成测试（真引导框架）。
 *
 * 复用 bootApp() 启动真实应用，验证：
 *  - tracer() 助手在引导后可解析 Tracer 实例；
 *  - 手动 start/end 经真实容器导出器落盘；
 *  - 真实 HTTP 请求穿过 TraceMiddleware 自动产生 SERVER 根 span 并 flush。
 *
 * 每个测试方法独立进程（避免 Tracer 单例缓存的导出器跨用例串扰）；
 * 用内存导出器替换真实 OTLP 端点，避免依赖外部 Collector / 网络。
 */
final class TracingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        \Kode\Framework\Observability\Trace\Tracer::resetOutbox();
    }

    /**
     * @param array<int, Span> $sink
     */
    private function installMemoryExporter(array &$sink): void
    {
        // 注意：全局 app()（kode/core）才有 ->container；TestCase::app() 是框架 Application 无 container。
        app()->container->singleton(SpanExporter::class, static function () use (&$sink) {
            return new class($sink) implements SpanExporter {
                public function __construct(private array &$sink)
                {
                }

                public function export(array $spans): void
                {
                    foreach ($spans as $span) {
                        $this->sink[] = $span;
                    }
                }

                public function name(): string
                {
                    return 'memory';
                }
            };
        });
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTracerResolvesWhenBooted(): void
    {
        self::assertInstanceOf(Tracer::class, tracer());
        self::assertTrue(tracer()->isEnabled());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testManualStartEndFlushToExporter(): void
    {
        $sink = [];
        $this->installMemoryExporter($sink);

        $t = tracer();
        $root = $t->start('manual', [], SpanKind::INTERNAL);
        $t->end($root);

        // 默认异步导出：请求路径仅入队，离请求路径 drain() 才真正落盘。
        $t->drain();

        self::assertCount(1, $sink);
        self::assertSame('manual', $sink[0]->name);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testHttpRequestCreatesServerSpan(): void
    {
        $sink = [];
        $this->installMemoryExporter($sink);

        // 404 仍穿过全局 TraceMiddleware，产生 SERVER 根 span（flush_on_request_end 默认开）
        $this->get('/');

        // 默认异步导出：离请求路径 drain() 才真正落盘。
        tracer()->drain();

        $names = array_map(static fn (Span $s): string => $s->name, $sink);
        self::assertNotEmpty($names);
        self::assertContains('GET /', $names);

        $server = array_filter($sink, static fn (Span $s): bool => $s->kind === SpanKind::SERVER);
        self::assertNotEmpty($server);
    }
}
