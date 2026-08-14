<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\Exporters\FileSpanExporter;
use Kode\Framework\Observability\Trace\OtlpMapper;
use Kode\Framework\Observability\Trace\Span;
use Kode\Framework\Observability\Trace\SpanKind;
use Kode\Framework\Observability\Trace\SpanStatus;
use Kode\Framework\Observability\Trace\SpansFlushed;
use Kode\Framework\Observability\Trace\Tracer;
use PHPUnit\Framework\TestCase;

/**
 * 分布式追踪（Tracer）单元测试。
 *
 * 覆盖：no-op（禁用）、采样、active 栈嵌套、缓冲、flush、异常事件、SpansFlushed 事件、
 * 文件导出器 NDJSON、OtlpMapper 结构。导出器统一用内存捕获器，避免依赖外部 Collector。
 */
final class TracingTest extends TestCase
{
    protected function setUp(): void
    {
        Context::clear();
        Tracer::resetOutbox();
    }

    /**
     * 构造一个把 span 写入 $sink 的内存导出器闭包（按引用捕获 $sink）。
     *
     * @param array<int, Span> $sink
     */
    private function exporterResolver(array &$sink): \Closure
    {
        return function () use (&$sink): SpanExporter {
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
        };
    }

    public function testDisabledTracerIsNoop(): void
    {
        $t = new Tracer(false, []);
        $span = $t->start('x');
        self::assertTrue($span->noop);
        $t->end($span);
        self::assertSame(0, $t->buffered());
        self::assertSame(0, $t->flush());
        self::assertNull($t->exporterName());
    }

    public function testStartEndBuffersAndFlushes(): void
    {
        $sink = [];
        $t = new Tracer(true, ['flush_on_request_end' => false], null, $this->exporterResolver($sink));

        $root = $t->start('root', [], SpanKind::SERVER);
        self::assertSame($root, $t->active());
        $t->end($root);

        self::assertNull($t->active());
        self::assertSame(1, $t->buffered());

        $n = $t->flush();
        self::assertSame(1, $n);
        self::assertCount(1, $sink);
        self::assertSame('root', $sink[0]->name);
        self::assertSame(SpanStatus::OK, $sink[0]->statusCode);
        self::assertSame(0, $t->buffered());
    }

    public function testNestingParentLink(): void
    {
        $sink = [];
        $t = new Tracer(true, ['flush_on_request_end' => false], null, $this->exporterResolver($sink));

        $root = $t->start('root');
        $child = $t->start('child', [], SpanKind::CLIENT);

        self::assertSame($root->spanId, $child->parentSpanId);
        self::assertSame($child, $t->active());

        $t->end($child);
        self::assertSame($root, $t->active());
        $t->end($root);
        $t->flush();

        self::assertCount(2, $sink);
    }

    public function testSamplingRatioZeroDrops(): void
    {
        $sink = [];
        $t = new Tracer(true, ['sample_ratio' => 0.0, 'flush_on_request_end' => false], null, $this->exporterResolver($sink));

        $span = $t->start('x');
        self::assertFalse($span->sampled);
        $t->end($span);

        self::assertSame(0, $t->buffered());
        self::assertSame(0, $t->flush());
    }

    public function testSamplingRatioOneKeeps(): void
    {
        $sink = [];
        $t = new Tracer(true, ['sample_ratio' => 1.0, 'flush_on_request_end' => false], null, $this->exporterResolver($sink));

        $span = $t->start('x');
        self::assertTrue($span->sampled);
        $t->end($span);

        self::assertSame(1, $t->buffered());
    }

    public function testRecordExceptionSetsError(): void
    {
        $sink = [];
        $t = new Tracer(true, ['flush_on_request_end' => false], null, $this->exporterResolver($sink));

        $span = $t->start('x');
        $t->recordException($span, new \RuntimeException('boom'));
        $t->end($span, SpanStatus::ERROR, 'boom');
        $t->flush();

        self::assertSame(SpanStatus::ERROR, $sink[0]->statusCode);
        self::assertCount(1, $sink[0]->events);
        self::assertSame('exception', $sink[0]->events[0]['name']);
    }

    public function testSpansFlushedEventDispatched(): void
    {
        $events = [];
        $sink = [];
        $t = new Tracer(
            true,
            ['flush_on_request_end' => false],
            static function (object $e) use (&$events): object {
                $events[] = $e;

                return $e;
            },
            $this->exporterResolver($sink),
        );

        $span = $t->start('x');
        $t->end($span);
        $t->flush();

        self::assertCount(1, $events);
        self::assertInstanceOf(SpansFlushed::class, $events[0]);
        self::assertTrue($events[0]->success);
        self::assertSame(1, $events[0]->count);
        self::assertSame('memory', $events[0]->exporter);
    }

    public function testFlushOnRequestEndAuto(): void
    {
        $sink = [];
        // 同步模式：请求结束自动 flush 直接落到导出器（验证原 SimpleSpanProcessor 语义）。
        $t = new Tracer(true, ['flush_on_request_end' => true, 'async' => false], null, $this->exporterResolver($sink));

        $root = $t->start('root');
        $t->end($root); // 栈空 → 自动 flush

        self::assertCount(1, $sink);
    }

    public function testAsyncEnqueueOffPath(): void
    {
        $sink = [];
        // 异步模式（默认）：请求结束仅入队 outbox，真实导出由 drain() 离请求路径执行。
        $t = new Tracer(true, ['flush_on_request_end' => true, 'async' => true], null, $this->exporterResolver($sink));

        $root = $t->start('root');
        $t->end($root); // 栈空 → 入队，不直接导出

        self::assertCount(0, $sink, '异步模式请求结束不应同步阻塞导出');

        $n = $t->drain(); // 离请求路径导出
        self::assertSame(1, $n);
        self::assertCount(1, $sink);
    }

    public function testFileExporterWritesNdjson(): void
    {
        $path = sys_get_temp_dir() . '/kode-trace-test-' . uniqid() . '.ndjson';
        @unlink($path);

        $exp = new FileSpanExporter($path);
        $span = new Span(
            str_repeat('a', 32),
            str_repeat('b', 16),
            null,
            'svc',
            SpanKind::SERVER,
            microtime(true),
            ['k' => 'v'],
            true,
        );
        $span->finish(microtime(true), SpanStatus::OK, '');
        $exp->export([$span]);

        self::assertFileExists($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        $obj = json_decode((string) $lines[0], true);
        self::assertSame('svc', $obj['name']);
        self::assertSame(str_repeat('a', 32), $obj['traceId']);
        self::assertSame(str_repeat('b', 16), $obj['spanId']);

        @unlink($path);
    }

    public function testOtlpMapperAnyValueAndNanos(): void
    {
        self::assertSame(['stringValue' => 'x'], OtlpMapper::anyValue('x'));
        self::assertSame(['intValue' => 5], OtlpMapper::anyValue(5));
        self::assertSame(['boolValue' => true], OtlpMapper::anyValue(true));
        self::assertSame(['doubleValue' => 1.5], OtlpMapper::anyValue(1.5));
        self::assertSame('1000000000', OtlpMapper::nanos(1.0));
    }

    public function testOtlpResourceSpansStructure(): void
    {
        $span = new Span(str_repeat('t', 32), str_repeat('s', 16), null, 'op', SpanKind::SERVER, 1.0, [], true);
        $span->finish(2.0);

        $payload = OtlpMapper::resourceSpans([$span], ['service.name' => 'demo']);

        self::assertArrayHasKey('resourceSpans', $payload);
        self::assertSame('demo', $payload['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue']);
        self::assertSame('op', $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['name']);
        self::assertSame('kode/framework', $payload['resourceSpans'][0]['scopeSpans'][0]['scope']['name']);
        self::assertSame('1000000000', $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['startTimeUnixNano']);
        self::assertSame('2000000000', $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano']);
    }
}
