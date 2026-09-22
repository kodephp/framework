<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\Framework\Scheduling\ClusterCoordinator;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Scheduling\Coordinator\LocalCoordinator;

/**
 * schedule.cluster.store 的语义锁定：它只是「是否启用集群协调」的开关。
 *
 * 该键曾被注释成「取值同 Cluster::make()（redis/file）」，但协调器从不据此选择后端——
 * 锁实际落在进程级 Cluster::store() 上。用例把这一口径钉死，避免注释与运行时再次分叉。
 */
final class ScheduleClusterCoordinationTest extends TestCase
{
    /** 本类自建配置，须拿到独立实例，避免复用他类的 CoreApp 单例。 */
    protected bool $independentApp = true;

    public function testStoreValueOnlyGatesTheCoordinator(): void
    {
        $this->bootApp();

        // 直改运行期配置：不依赖引导期合并，断言只针对 coordinator() 的读取口径。
        $config = resolve(Config::class);
        $config->set('schedule.cluster', ['store' => '', 'ttl' => 7.5]);

        $dispatcher = new ScheduleDispatcher();
        $coordinator = new \ReflectionMethod($dispatcher, 'coordinator');

        self::assertInstanceOf(LocalCoordinator::class, $coordinator->invoke($dispatcher), 'store 为空应本地恒派发');

        $config->set('schedule.cluster.store', 'redis');

        $picked = $coordinator->invoke($dispatcher);
        self::assertInstanceOf(ClusterCoordinator::class, $picked);
        self::assertSame(7.5, self::prop($picked, 'ttl'), 'ttl 应透传给派发锁');
        self::assertSame('redis', self::prop($picked, 'store'), 'store 仅留档，不改写进程级 Cluster 后端');
    }

    private static function prop(object $target, string $name): mixed
    {
        $prop = new \ReflectionProperty($target, $name);

        return $prop->getValue($target);
    }
}
