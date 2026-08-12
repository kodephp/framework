<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Health\HealthChecker;
use Kode\Framework\Testing\TestCase;

/**
 * 健康检查：HealthChecker 单元 + /health* 端点集成。
 */
final class HealthCheckTest extends TestCase
{
    // ------------------------------------------------------------------
    // HealthChecker 单元
    // ------------------------------------------------------------------

    public function testDisabledCheckIsSkipped(): void
    {
        $r = (new HealthChecker(['checks' => ['db' => false]], null))->check();
        self::assertTrue($r['healthy']);
        self::assertArrayHasKey('app', $r['checks']);
        self::assertArrayNotHasKey('db', $r['checks']);
    }

    public function testCustomFailingProbeMarksUnhealthy(): void
    {
        $r = (new HealthChecker([
            'checks' => [
                'boom' => static function (): string {
                    throw new \RuntimeException('boom');
                },
            ],
        ], null))->check();

        self::assertFalse($r['healthy']);
        self::assertStringStartsWith('error', $r['checks']['boom']);
    }

    public function testCustomOkProbeKeepsHealthy(): void
    {
        $r = (new HealthChecker([
            'checks' => ['ping' => static fn(): string => 'ok'],
        ], null))->check();

        self::assertTrue($r['healthy']);
        self::assertSame('ok', $r['checks']['ping']);
    }

    public function testDbNotConfiguredWhenNoConnections(): void
    {
        if (\Kode\Database\Db\Db::getConnections() !== []) {
            self::markTestSkipped('测试环境已配置数据库连接');
        }

        $r = (new HealthChecker(['checks' => ['db' => true]], null))->check();
        self::assertTrue($r['healthy']);
        self::assertSame('not_configured', $r['checks']['db']);
    }

    // ------------------------------------------------------------------
    // 端点集成
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(getcwd());
    }

    public function testReadinessReturnsValidStatus(): void
    {
        $status = $this->get('/health/ready')->status();
        self::assertContains($status, [200, 503]);
    }

    public function testReadinessBodyHasChecks(): void
    {
        $json = $this->get('/health/ready')->json();
        self::assertArrayHasKey('checks', $json);
        self::assertArrayHasKey('app', $json['checks']);
    }

    public function testHealthAggregateHasComponents(): void
    {
        $json = $this->get('/health')->json();
        self::assertSame('ok', $json['status']);
        self::assertArrayHasKey('components', $json);
        self::assertArrayHasKey('app', $json['components']);
    }
}
