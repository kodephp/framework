<?php

declare(strict_types=1);

namespace Kode\Framework\Lock;

use RuntimeException;

/**
 * 内置锁后端（零依赖薄壳实现）。
 *
 * 两种运行模式：
 *  - memory：进程内静态表，覆盖单实例与同进程内并发（Fiber / 协程 / 同请求多次调用）；
 *  - file：以文件落盘（storage_path('framework/locks') 或系统临时目录），覆盖同主机多进程
 *    （如多 worker、queue:work 多进程、同机多副本 cron）的互斥。
 *
 * 跨主机分布式锁（Redis / etcd / DB）不属于本类职责——实现 {@see LockManager} 并经
 * {@see \Kode\Framework\Providers\LockServiceProvider} 绑定即可替换，API 完全一致。
 *
 * 复制安全：文件写入使用 LOCK_EX 原子落盘；读取时校验到期时间戳实现惰性过期。
 */
final class StaticLockManager implements LockManager
{
    /** @var array<string, array{owner: string, expires: float}> */
    private array $memory = [];

    private readonly string $ownerId;

    /**
     * @param array<string, mixed> $config
     * @param ?string $dir file 模式的锁目录（null 时由 Provider 注入 storage_path 或系统临时目录）
     * @param ?\Closure(object): object $dispatcher 事件派发闭包（由 Provider 注入，解耦事件系统启动顺序）
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?string $dir = null,
        private readonly ?\Closure $dispatcher = null,
    ) {
        $this->ownerId = bin2hex(random_bytes(12));
    }

    public function acquire(string $key, int $ttl = 30, ?string $owner = null): bool
    {
        $owner = $owner ?? $this->ownerId;
        $expires = microtime(true) + max(1, $ttl);

        if ($this->isLocked($key)) {
            $current = $this->read($key);
            // 同一 owner 重入：刷新 TTL 视为成功
            if ($current !== null && $current['owner'] === $owner) {
                $this->write($key, ['owner' => $owner, 'expires' => $expires]);

                return true;
            }

            return false;
        }

        $this->write($key, ['owner' => $owner, 'expires' => $expires]);
        $this->dispatch(new LockAcquired($key, $owner, $ttl));

        return true;
    }

    public function release(string $key, ?string $owner = null): bool
    {
        $owner = $owner ?? $this->ownerId;
        $current = $this->read($key);

        if ($current === null) {
            return true; // 本就不持有
        }
        if ($current['owner'] !== $owner) {
            return false; // owner 不匹配，拒绝释放
        }

        $this->clear($key);
        $this->dispatch(new LockReleased($key, $owner, false));

        return true;
    }

    public function isLocked(string $key): bool
    {
        $current = $this->read($key);
        if ($current === null) {
            return false;
        }
        if ($current['expires'] <= microtime(true)) {
            $this->clear($key); // 惰性过期

            return false;
        }

        return true;
    }

    public function owner(string $key): ?string
    {
        $current = $this->read($key);
        if ($current === null || $current['expires'] <= microtime(true)) {
            return null;
        }

        return $current['owner'];
    }

    public function ttl(string $key): ?int
    {
        $current = $this->read($key);
        if ($current === null) {
            return null;
        }
        $remaining = (int) ceil($current['expires'] - microtime(true));
        if ($remaining <= 0) {
            $this->clear($key);

            return null;
        }

        return $remaining;
    }

    public function forceRelease(string $key): bool
    {
        $current = $this->read($key);
        if ($current === null) {
            return true;
        }
        $this->clear($key);
        $this->dispatch(new LockReleased($key, $current['owner'], true));

        return true;
    }

    public function keys(): array
    {
        if ($this->dir === null) {
            $keys = array_keys($this->memory);
        } else {
            $keys = [];
            foreach (glob($this->dir . '/*.lock') ?: [] as $file) {
                $keys[] = basename($file, '.lock');
            }
        }

        return array_values(array_filter($keys, fn (string $k): bool => $this->isLocked($k)));
    }

    public function run(string $key, callable $work, int $ttl = 30): mixed
    {
        if (!$this->acquire($key, $ttl)) {
            throw new LockAcquireException($key);
        }
        try {
            return $work();
        } finally {
            $this->release($key);
        }
    }

    // ------------------------------------------------------------------
    // 内部：读 / 写 / 清（memory 与 file 双后端）
    // ------------------------------------------------------------------

    /**
     * @return array{owner: string, expires: float}|null
     */
    private function read(string $key): ?array
    {
        if ($this->dir === null) {
            return $this->memory[$key] ?? null;
        }

        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array{owner: string, expires: float} $data
     */
    private function write(string $key, array $data): void
    {
        if ($this->dir === null) {
            $this->memory[$key] = $data;

            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建锁目录：' . $this->dir);
        }
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($data), LOCK_EX);
        rename($tmp, $file);
    }

    private function clear(string $key): void
    {
        if ($this->dir === null) {
            unset($this->memory[$key]);

            return;
        }
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.lock';
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
