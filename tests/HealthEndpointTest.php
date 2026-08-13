<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 健康端点集成测试（真引导框架，每条用例独立进程）。
 *
 * 验证：
 *  - /health/live 永远 200（liveness，不含外部依赖）；
 *  - /health/ready 200 + 含 status / checks（测试环境 HEALTH_CHECK_DB=false，依赖未就绪不误报）；
 *  - /health 聚合视图含 version / components，且 tenant_storage 能力探针随启用出现。
 *
 * 每个测试方法独立进程（Application 为进程级单例），避免 Provider 接线串扰。
 */
final class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testLiveAlways200(): void
    {
        $this->get('/health/live')->assertStatus(200);
        self::assertSame('ok', $this->get('/health/live')->json()['status']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testReadyReturnsStatusAndChecks(): void
    {
        $r = $this->get('/health/ready')->assertStatus(200)->json();

        self::assertArrayHasKey('status', $r);
        self::assertArrayHasKey('checks', $r);
        self::assertSame('ok', $r['status']);
        self::assertSame('ok', $r['checks']['app']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testAggregateExposesVersionAndComponents(): void
    {
        $r = $this->get('/health')->assertStatus(200)->json();

        self::assertSame(Application::VERSION, $r['version']);
        self::assertArrayHasKey('components', $r);
        self::assertArrayHasKey('app', $r['components']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTenantStorageCapabilityProbeAppearsWhenEnabled(): void
    {
        // phpunit.xml 已启用 tenant.storage，探针应出现在结果中且为 ok。
        $r = $this->get('/health/ready')->json();

        self::assertArrayHasKey('tenant_storage', $r['checks']);
        self::assertSame('ok', $r['checks']['tenant_storage']);
    }
}
