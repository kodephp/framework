<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

/**
 * 幂等管理器契约（薄壳层核心抽象）。
 *
 * 与 {@see \Kode\Framework\Lock\LockManager} 的边界：
 *  - 锁 = 并发互斥（同一时刻只有一个持有者运行）；锁释放后下一次可再运行；
 *  - 幂等 = 重试安全（同一 key 在 TTL 内只「成功处理一次」，重放返回一致语义，不重复执行业务）。
 *
 * 典型场景：HTTP 幂等键（Stripe 风格）、消息队列至少一次投递下的去重、支付/下单防重复提交。
 */
interface IdempotencyManager
{
    /**
     * 幂等包裹：若 key 未处理过 → 记录并执行业务，返回其结果（业务抛异常则回滚记录，允许重试）；
     * 若 key 已处理过（TTL 内）→ 抛出 {@see DuplicateRequest}（上层据此返回 409 / 缓存响应）。
     *
     * @param callable(): mixed $work
     * @return mixed 业务返回值
     */
    public function once(string $key, callable $work, int $ttl = 3600): mixed;

    /**
     * 轻量判重：返回 true 表示「首次」（顺带记录 key），false 表示「重复」。
     * 不缓存业务结果，适合仅需要去重放行、自行处理响应的场景。
     */
    public function seen(string $key, int $ttl = 3600): bool;

    /**
     * 删除记录（重试放行 / 运维清理）。
     */
    public function forget(string $key): void;

    /**
     * 底层记录存储（供运维命令列出 / 清理记录；生产业务不应直接依赖）。
     */
    public function store(): IdempotencyStore;
}
