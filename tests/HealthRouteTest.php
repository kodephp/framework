<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;


/**
 * 健康检查端点 + TestCase 基类冒烟测试。
 *
 * 启动真实应用，验证 /health、/health/live、/health/ready、/ping 均可用。
 * /health/ready 依赖数据库连通性，因此断言其在 200/503 之间（不绑定具体环境）。
 */
final class HealthRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(self::SKELETON_ROOT);
    }

    public function testHealthAggregate(): void
    {
        $res = $this->get('/health');
        $res->assertStatus(200);
        self::assertSame('ok', $res->json()['status']);
        self::assertArrayHasKey('version', $res->json());
    }

    public function testLivenessAlwaysOk(): void
    {
        $this->get('/health/live')->assertStatus(200);
    }

    public function testReadinessReturnsValidStatus(): void
    {
        $status = $this->get('/health/ready')->status();
        self::assertContains($status, [200, 503]);
    }

    public function testPing(): void
    {
        $this->get('/ping')->assertStatus(200)->assertSee('pong');
    }
}
