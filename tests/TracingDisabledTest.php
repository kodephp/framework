<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Context\Context;
use Kode\Framework\Observability\Trace\Tracer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 分布式追踪「关闭态」测试。
 *
 *  - 未引导框架时，tracer() 助手返回 null（null 安全，不报错）。
 *  - 显式禁用的 Tracer 不产生任何 span（start 返回 no-op，end/flush 无副作用）。
 */
final class TracingDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        Context::clear();
    }

    #[RunInSeparateProcess]
    public function testHelperReturnsNullWhenNotBooted(): void
    {
        self::assertNull(tracer());
    }

    public function testDisabledTracerProducesNoSpans(): void
    {
        $t = new Tracer(false, []);

        $span = $t->start('x');
        self::assertTrue($span->noop);

        $t->end($span);

        self::assertSame(0, $t->buffered());
        self::assertSame(0, $t->flush());
        self::assertNull($t->exporterName());
    }
}
