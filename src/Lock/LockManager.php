<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

/**
 * 分布式锁契约（薄壳层核心抽象）。
 *
 * 框架只定义契约 + 内置静态后端（{@see StaticLockManager}），不重新发明分布式协调算法。
 * 真正跨主机 / 跨进程的共享锁由实现本契约的后端提供（如基于 kode/cache 的 Redis 适配器、
 * etcd / 数据库乐观锁等），通过 {@see \Kode\Framework\Providers\LockServiceProvider} 绑定即可零改动复用。
 *
 * 设计要点：
 *  - owner 令牌：每个管理器实例持有唯一 owner 令牌；释放 / 强制释放仅当 owner 匹配时生效，
 *    避免多副本场景下误释放他人持有的锁。
 *  - 惰性过期：后端在每次访问时校验 TTL，过期即视为未持有（无需后台 reaper）。
 *  - 事件：acquire / release 成功时派发 {@see LockAcquired} / {@see LockReleased}（由 Provider 注入派发闭包）。
 */
interface LockManager
{
    /**
     * 尝试获取锁。
     *
     * @param string $key 锁键
     * @param int $ttl 存活秒数（默认 30），到期自动释放
     * @param ?string $owner 自定义 owner 令牌；null 时使用管理器实例令牌
     * @return bool 获取成功返回 true（含同一 owner 重入刷新 TTL）；已被他人持有时 false
     */
    public function acquire(string $key, int $ttl = 30, ?string $owner = null): bool;

    /**
     * 释放锁（仅 owner 匹配时生效）。
     *
     * @return bool 释放成功（或本就不持有）返回 true；owner 不匹配返回 false
     */
    public function release(string $key, ?string $owner = null): bool;

    /**
     * 当前是否处于锁定状态（含 TTL 未过期）。
     */
    public function isLocked(string $key): bool;

    /**
     * 当前持有锁的 owner 令牌（未持有时 null）。
     */
    public function owner(string $key): ?string;

    /**
     * 剩余 TTL（秒）；未持有时 null。
     */
    public function ttl(string $key): ?int;

    /**
     * 强制释放（运维兜底 / 死锁清理）。忽略 owner，直接解除并派发 forced 事件。
     */
    public function forceRelease(string $key): bool;

    /**
     * 列出当前持有的锁键（已过期者不计入）。
     *
     * @return string[]
     */
    public function keys(): array;

    /**
     * 便捷包裹：获取锁 → 执行 → 释放（finally 保证释放）。
     *
     * 获取失败抛出 {@see LockAcquireException}；业务返回值原样透传。
     *
     * @param callable(): mixed $work
     * @return mixed 业务返回值
     */
    public function run(string $key, callable $work, int $ttl = 30): mixed;
}
