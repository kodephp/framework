<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\ServiceDiscovery\ServiceDiscovery;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 服务发现集成测试：真实引导框架，验证 ServiceDiscoveryServiceProvider 接线、
 * config/services.php 播种、service()/service_url() 助手、运行期注册与解析。
 *
 * 注意：避免依赖全局 app() 助手（其单例跨测试类残留），统一用 bootApp() 返回的实例读取。
 */
final class ServiceDiscoveryIntegrationTest extends TestCase
{
    private \Kode\Framework\Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->bootApp(getcwd());
    }

    #[RunInSeparateProcess]
    public function testProviderWiresServiceDiscoveryAndSeedsFromConfig(): void
    {
        // 容器已 bind + alias 出 ServiceDiscovery 单例。
        self::assertInstanceOf(
            ServiceDiscovery::class,
            $this->app->makeService(ServiceDiscovery::class),
        );

        // config/services.php 内置的 example-upstream 已播种。
        self::assertContains('example-upstream', service()->names());
        self::assertSame('http://127.0.0.1:8080', service_url('example-upstream'));
    }

    #[RunInSeparateProcess]
    public function testHelperResolvesAndReturnsNullForUnknown(): void
    {
        // 未知服务解析为 null（无健康实例）。
        self::assertNull(service('does-not-exist'));
        self::assertNull(service_url('does-not-exist'));

        // 已知服务返回实例且地址正确。
        $instance = service('example-upstream');
        self::assertNotNull($instance);
        self::assertSame('http://127.0.0.1:8080', $instance->url());
    }

    #[RunInSeparateProcess]
    public function testRuntimeRegisterAndResolve(): void
    {
        /** @var ServiceDiscovery $sd */
        $sd = service();

        $sd->register(new \Kode\Framework\ServiceDiscovery\ServiceInstance(
            id: 'runtime://1.2.3.4:9090',
            name: 'runtime-svc',
            host: '1.2.3.4',
            port: 9090,
            scheme: 'http',
        ));

        self::assertContains('runtime-svc', $sd->names());
        self::assertSame('http://1.2.3.4:9090', service_url('runtime-svc'));

        $stats = $sd->stats();
        self::assertSame(1, $stats['runtime-svc']['healthy']);
    }
}
