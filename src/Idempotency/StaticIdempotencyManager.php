<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

/**
 * 内置幂等管理器（零依赖薄壳实现）。
 *
 * 委托 {@see IdempotencyStore} 完成记录持久化，并在关键节点派发事件：
 *  - 首次记录成功 → {@see IdempotencyRecorded}；
 *  - 检测到重复（once 命中已存在 key）→ {@see IdempotencyHit}（上层据此返回 409 / 缓存响应）。
 *
 * once() 的事务语义：业务正常返回 → 记录保留（TTL 内重放视为重复）；
 * 业务抛异常 → 回滚记录（forget），使调用方可在修复后重试，避免「永久死锁在重复态」。
 */
final class StaticIdempotencyManager implements IdempotencyManager
{
    /**
     * @param ?\Closure(object): object $dispatcher 事件派发闭包（由 Provider 注入，解耦事件系统启动顺序）
     */
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly ?\Closure $dispatcher = null,
    ) {
    }

    public function once(string $key, callable $work, int $ttl = 3600): mixed
    {
        if ($this->store->has($key)) {
            $this->dispatch(new IdempotencyHit($key));

            throw new DuplicateRequest($key);
        }

        // 先占位（put 返回 false 说明并发下被他人抢先）
        if (!$this->store->put($key, $ttl)) {
            $this->dispatch(new IdempotencyHit($key));

            throw new DuplicateRequest($key);
        }

        $this->dispatch(new IdempotencyRecorded($key, $ttl));

        try {
            return $work();
        } catch (\Throwable $e) {
            $this->store->forget($key); // 业务失败回滚，允许重试

            throw $e;
        }
    }

    public function seen(string $key, int $ttl = 3600): bool
    {
        if ($this->store->has($key)) {
            $this->dispatch(new IdempotencyHit($key));

            return false;
        }

        $recorded = $this->store->put($key, $ttl);
        if ($recorded) {
            $this->dispatch(new IdempotencyRecorded($key, $ttl));
        }

        return $recorded;
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }

    public function attach(string $key, ?string $payload): void
    {
        $this->store->attach($key, $payload);
    }

    public function replay(string $key): ?string
    {
        return $this->store->payload($key);
    }

    public function store(): IdempotencyStore
    {
        return $this->store;
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
