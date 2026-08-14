<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

/**
 * 幂等记录持久化契约（薄壳层核心抽象）。
 *
 * 框架只定义契约 + 内置静态后端（{@see StaticIdempotencyStore}），不重新发明分布式 KV。
 * 真正跨主机共享的去重（Redis / etcd / DB）由实现本契约的后端提供，经
 * {@see \Kode\Framework\Providers\IdempotencyServiceProvider} 绑定即可零改动复用。
 *
 * 记录语义：仅保存「某 key 已处理过」的事实与到期时间（TTL 惰性过期，无需后台 reaper）；
 * 不做响应体缓存（那是上层 HTTP 幂等中间件的职责，按需要接专用存储）。
 */
interface IdempotencyStore
{
    /**
     * 该 key 是否已记录（且未过期）。
     */
    public function has(string $key): bool;

    /**
     * 记录 key 已处理（带 TTL 秒）。
     *
     * @return bool 新写入（首次）返回 true；已存在（重复）返回 false
     */
    public function put(string $key, int $ttl): bool;

    /**
     * 删除记录（重试放行 / 运维清理）。
     */
    public function forget(string $key): void;

    /**
     * 剩余 TTL（秒）；未记录时 null。
     */
    public function ttl(string $key): ?int;

    /**
     * 当前已记录的 key 列表（已过期者不计入）。
     *
     * @return string[]
     */
    public function keys(): array;

    /**
     * 清理全部过期记录（主动调用，非必需）。
     */
    public function prune(): void;
}
