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
 * 记录语义：保存「某 key 已处理过」的事实、到期时间（TTL 惰性过期，无需后台 reaper），
 * 以及一个可选的响应载荷缓存（供上层 HTTP 幂等中间件在重放时原样返回首次响应）。
 * 不做「分布式」去重——那是实现本契约的后端（Redis / etcd / DB）的职责。
 */
interface IdempotencyStore
{
    /**
     * 该 key 是否已记录（且未过期）。
     */
    public function has(string $key): bool;

    /**
     * 记录 key 已处理（带 TTL 秒，可选缓存响应载荷）。
     *
     * @param ?string $payload 缓存的响应载荷（如 HTTP 中间件编码的状态/头/体），null 表示不缓存
     * @return bool 新写入（首次）返回 true；已存在（重复）返回 false
     */
    public function put(string $key, int $ttl, ?string $payload = null): bool;

    /**
     * 读取已缓存的响应载荷；未记录 / 已过期 / 未缓存时返回 null。
     */
    public function payload(string $key): ?string;

    /**
     * 给已存在的记录追加 / 覆盖响应载荷（首次响应产出后调用；key 不存在则静默忽略）。
     */
    public function attach(string $key, ?string $payload): void;

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
