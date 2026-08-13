<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\ServiceDiscovery\ServiceDiscovered;
use Kode\Framework\ServiceDiscovery\ServiceDiscovery;
use Kode\Framework\ServiceDiscovery\ServiceInstance;
use Kode\Framework\ServiceDiscovery\ServiceUnhealthy;
use Kode\Framework\ServiceDiscovery\StaticServiceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * 服务发现薄壳层：ServiceInstance / StaticServiceRegistry / ServiceDiscovery 管理器（隔离单测）。
 *
 * 集成接线（Provider + 助手 + 配置播种）见 ServiceDiscoveryIntegrationTest。
 */
final class ServiceDiscoveryTest extends TestCase
{
    private function instance(string $name, string $host, int $port, bool $healthy = true): ServiceInstance
    {
        return new ServiceInstance(
            id: sprintf('%s://%s:%d', 'http', $host, $port),
            name: $name,
            host: $host,
            port: $port,
            scheme: 'http',
            healthy: $healthy,
        );
    }

    public function testServiceInstanceUrlAndHealth(): void
    {
        $i = $this->instance('pay', '10.0.0.1', 8080);
        self::assertSame('http://10.0.0.1:8080', $i->url());
        self::assertTrue($i->isHealthy());
        self::assertSame('pay', $i->name);
    }

    public function testStaticRegistrySeedsAndDiscovers(): void
    {
        $reg = new StaticServiceRegistry();
        $reg->seed([
            'search' => [
                ['host' => '10.0.0.1', 'port' => 9200],
                ['host' => '10.0.0.2', 'port' => 9200],
            ],
        ]);

        self::assertSame(['search'], $reg->names());
        self::assertCount(2, $reg->discover('search'));
        self::assertSame(2, $reg->count());
        self::assertSame('http://10.0.0.1:9200', $reg->discover('search')[0]->url());
    }

    public function testStaticRegistrySkipsEntriesWithoutHost(): void
    {
        $reg = new StaticServiceRegistry();
        $reg->seed(['x' => [['port' => 80], ['host' => '1.2.3.4', 'port' => 81]]]);

        self::assertSame(['x'], $reg->names());
        self::assertCount(1, $reg->discover('x'));
        self::assertSame('http://1.2.3.4:81', $reg->discover('x')[0]->url());
    }

    public function testRegistryRegisterReportsNewAndDeduplicates(): void
    {
        $reg = new StaticServiceRegistry();
        $a = $this->instance('pay', '1.1.1.1', 80);

        self::assertTrue($reg->register($a));      // 新增
        self::assertFalse($reg->register($a));     // 重复（同 id）不算新增
        self::assertCount(1, $reg->discover('pay'));

        $reg->unregister($a->id);
        self::assertSame([], $reg->discover('pay'));
        self::assertSame([], $reg->names());
        self::assertNull($reg->get($a->id));
    }

    public function testResolveRoundRobinDistributesAcrossHealthy(): void
    {
        $reg = new StaticServiceRegistry();
        $reg->seed([
            'pay' => [
                ['host' => '1.1.1.1', 'port' => 80],
                ['host' => '1.1.1.2', 'port' => 80],
                ['host' => '1.1.1.3', 'port' => 80],
            ],
        ]);

        $sd = new ServiceDiscovery($reg);
        $seen = [];
        for ($i = 0; $i < 6; ++$i) {
            $seen[$sd->resolve('pay')->host] = true;
        }

        // 三个健康实例都被轮询到
        self::assertCount(3, $seen);
    }

    public function testResolveSkipsUnhealthyAndFallsBackToNull(): void
    {
        $reg = new StaticServiceRegistry();
        $healthy = $this->instance('pay', '1.1.1.1', 80, true);
        $sick = $this->instance('pay', '1.1.1.2', 80, false);
        $reg->register($healthy);
        $reg->register($sick);

        $sd = new ServiceDiscovery($reg);
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('1.1.1.1', $sd->resolve('pay')->host);
        }

        // 唯一实例不健康 → 无可用
        $reg2 = new StaticServiceRegistry();
        $reg2->register($this->instance('lonely', '2.2.2.2', 80, false));
        $sd2 = new ServiceDiscovery($reg2);
        self::assertNull($sd2->resolve('lonely'));
        self::assertNull($sd2->url('lonely'));
    }

    public function testResolveStrategiesRandomAndFirst(): void
    {
        $reg = new StaticServiceRegistry();
        $reg->seed([
            'pay' => [
                ['host' => '1.1.1.1', 'port' => 80],
                ['host' => '1.1.1.2', 'port' => 80],
            ],
        ]);

        $sd = new ServiceDiscovery($reg);
        self::assertSame('1.1.1.1', $sd->resolve('pay', 'first')->host);

        $random = $sd->resolve('pay', 'random');
        self::assertContains($random->host, ['1.1.1.1', '1.1.1.2']);
    }

    public function testHeartbeatMarksUnhealthyAndDispatchesEvent(): void
    {
        $reg = new StaticServiceRegistry();
        $inst = $this->instance('pay', '1.1.1.1', 80, true);
        $reg->register($inst);

        $events = [];
        $sd = new ServiceDiscovery($reg, function (object $e) use (&$events): object {
            $events[] = $e;

            return $e;
        });

        // 健康 → 不健康：应派发 ServiceUnhealthy
        $sd->heartbeat($inst->id, false);
        self::assertFalse($inst->healthy);
        self::assertNotNull($inst->lastHealthAt);
        self::assertCount(1, $events);
        self::assertInstanceOf(ServiceUnhealthy::class, $events[0]);
        self::assertSame('pay', $events[0]->name);

        // 已经不健康 → 再次 heartbeat(false)：不再派发（无状态转换）
        $sd->heartbeat($inst->id, false);
        self::assertCount(1, $events);

        // 恢复健康：resolve 重新可用（无新事件，因仅「健康→不健康」派发）
        $sd->heartbeat($inst->id, true);
        self::assertTrue($inst->healthy);
        self::assertSame('1.1.1.1', $sd->resolve('pay')->host);
        self::assertCount(1, $events);
    }

    public function testRegisterDispatchesServiceDiscovered(): void
    {
        $reg = new StaticServiceRegistry();
        $captured = null;
        $sd = new ServiceDiscovery($reg, function (object $e) use (&$captured): object {
            $captured = $e;

            return $e;
        });

        $inst = $this->instance('new', '3.3.3.3', 80);
        $sd->register($inst);

        self::assertInstanceOf(ServiceDiscovered::class, $captured);
        self::assertSame('new', $captured->name);
    }

    public function testStatsCountsHealthyAndUnhealthy(): void
    {
        $reg = new StaticServiceRegistry();
        $reg->register($this->instance('pay', '1.1.1.1', 80, true));
        $reg->register($this->instance('pay', '1.1.1.2', 80, false));
        $reg->register($this->instance('pay', '1.1.1.3', 80, true));

        $sd = new ServiceDiscovery($reg);
        $stats = $sd->stats();

        self::assertSame(['pay'], array_keys($stats));
        self::assertSame(3, $stats['pay']['total']);
        self::assertSame(2, $stats['pay']['healthy']);
        self::assertSame(1, $stats['pay']['unhealthy']);
    }

    public function testDispatcherNullSafeWhenNotProvided(): void
    {
        $reg = new StaticServiceRegistry();
        $inst = $this->instance('pay', '1.1.1.1', 80, true);
        $reg->register($inst);

        // 无 dispatcher：register / heartbeat 不应抛错
        $sd = new ServiceDiscovery($reg);
        $sd->register($this->instance('pay', '1.1.1.2', 80));
        $sd->heartbeat($inst->id, false);

        self::assertFalse($inst->healthy);
    }
}
