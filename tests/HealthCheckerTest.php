<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Health\HealthChecked;
use PHPUnit\Framework\TestCase;

/**
 * 健康探针聚合器单元测试（不引导完整应用，直接构造 HealthChecker）。
 *
 * 无引导环境下，能力感知探针的全局助手（config_center / service / tracer / tenant_storage）
 * 均返回 null → not_configured，因此可稳定验证「未接线不计入失败」与降级逻辑。
 */
final class HealthCheckerTest extends TestCase
{
    public function testDefaultCheckIsHealthy(): void
    {
        $r = (new HealthChecker([], null))->check();

        self::assertTrue($r['healthy']);
        self::assertSame('ok', $r['checks']['app']);
    }

    public function testCustomClosureOkAppears(): void
    {
        $r = (new HealthChecker([
            'checks' => ['custom' => static fn() => 'ok'],
        ], null))->check();

        self::assertTrue($r['healthy']);
        self::assertSame('ok', $r['checks']['custom']);
    }

    public function testCustomClosureErrorDegrades(): void
    {
        $r = (new HealthChecker([
            'checks' => ['custom' => static fn() => 'error: boom'],
        ], null))->check();

        self::assertFalse($r['healthy']);
        self::assertSame('error: boom', $r['checks']['custom']);
    }

    public function testCustomClosureThrowingDegrades(): void
    {
        $r = (new HealthChecker([
            'checks' => ['custom' => static function () {
                throw new \RuntimeException('kaboom');
            }],
        ], null))->check();

        self::assertFalse($r['healthy']);
        self::assertStringStartsWith('error: ', $r['checks']['custom']);
    }

    public function testUnknownBuiltinNameIsNotConfigured(): void
    {
        $r = (new HealthChecker([
            'checks' => ['mystery' => true],
        ], null))->check();

        self::assertTrue($r['healthy']);
        self::assertSame('not_configured', $r['checks']['mystery']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testCapabilityProbesAreNotConfiguredWhenNotWired(): void
    {
        // 独立进程：app() 未引导，能力助手均返回 null → not_configured（不计入失败）。
        $r = (new HealthChecker([], null))->check();

        foreach (['config_center', 'service_discovery', 'tracing', 'tenant_storage'] as $name) {
            self::assertArrayHasKey($name, $r['checks']);
            self::assertSame('not_configured', $r['checks'][$name]);
        }
        self::assertTrue($r['healthy']);
    }

    public function testCapabilityProbeCanBeDisabledViaConfigFalse(): void
    {
        $r = (new HealthChecker([
            'checks' => ['tracing' => false],
        ], null))->check();

        self::assertArrayNotHasKey('tracing', $r['checks']);
    }

    public function testDispatcherReceivesHealthChecked(): void
    {
        $captured = null;
        $dispatcher = static function (HealthChecked $e) use (&$captured): void {
            $captured = $e;
        };

        $r = (new HealthChecker([
            'checks' => ['custom' => static fn() => 'error: x'],
        ], null, $dispatcher))->check('ready');

        self::assertInstanceOf(HealthChecked::class, $captured);
        self::assertFalse($captured->healthy);
        self::assertSame('ready', $captured->mode);
        self::assertSame($r['checks'], $captured->checks);
    }

    public function testNullDispatcherIsNoop(): void
    {
        // 不应抛出异常。
        (new HealthChecker([], null))->check();
        self::assertTrue(true);
    }
}
