<?php

declare(strict_types=1);

namespace Kode\Framework\Scheduling;

use Kode\Process\Cluster;
use Kode\Scheduling\Contract\CoordinatorInterface;
use Throwable;

/**
 * 集群协调器：实现 kode/scheduling 的 {@see CoordinatorInterface}，
 * 把「本节点本调度轮次是否应当派发」的裁决委托给 kode/process 的分布式锁。
 *
 * kode/scheduling 的 Scheduler 每个 run() 轮次会先调 tick()（释放上轮锁），
 * 再调 shouldDispatch()（本轮抢夺派发锁）。抢到锁的节点才派发，从而保证集群内
 * 同一时刻至多执行一次——即框架 #[Cron(cluster: true)] 的语义。
 *
 * 失败降级（与 kode/process ClusterCron 同理念，不 fatal）：
 *  - 未配置 schedule.cluster.store 时本协调器不会被挂上（调度器用 LocalCoordinator 恒派发）；
 *  - 即便被挂上，若协调存储不可用 / 锁获取抛异常，shouldDispatch() 降级返回 true（本地派发，
 *    多机可能重复，但服务可用）——避免因为协调层故障导致定时任务整体停摆。
 */
final class ClusterCoordinator implements CoordinatorInterface
{
    /** 上轮次持有的锁句柄（tick() 时释放）。 */
    private mixed $held = null;

    /**
     * @param string $store 协调存储后端名（与 Cluster::make() 一致，如 'redis'/'file'）。
     * @param float  $ttl  派发锁 TTL（秒），应 >= 单轮任务最大耗时。
     * @param string $key  锁键名。
     */
    public function __construct(
        private readonly string $store,
        private readonly float $ttl = 30.0,
        private readonly string $key = 'kode:schedule:dispatch',
    ) {
    }

    /**
     * 轮次开始：释放上轮持有的派发锁（如有）。
     */
    public function tick(): void
    {
        if ($this->held !== null) {
            try {
                $this->held->release();
            } catch (Throwable) {
                // 释放失败不影响本轮（TTL 到期会自动过期）。
            }
            $this->held = null;
        }
    }

    /**
     * 本轮是否应当派发：抢到分布式锁的节点返回 true。
     */
    public function shouldDispatch(): bool
    {
        try {
            $lock = Cluster::lock($this->key, $this->ttl);
            $this->held = $lock;

            return $lock->isLocked();
        } catch (Throwable $e) {
            // 协调存储不可用 → 降级为本地派发（可用优先于强一致）。
            logger()->warning(
                sprintf('[schedule] 集群锁获取失败，退化为本地派发：%s', $e->getMessage())
            );

            return true;
        }
    }
}
