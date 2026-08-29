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
 * 复制安全（v1.0.0 修复）：file 模式以 fopen 'x'（O_CREAT|O_EXCL）独占创建作为获取原语，
 * 未过期锁的互斥为强原子；释放为「flock 下重读校验 owner 再 unlink」的原子 compare-and-delete。
 * 已知限制：对「恰好过期」锁的惰性抢占（读→判过期→unlink→重建）仍存在极窄竞态窗口——
 * 两个进程同时抢占同一把刚过期的锁时，理论上可能双双成功。需要严格互斥语义的分布式场景请
 * 实现 {@see LockManager} 接入 Redis 等后端。
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

        if ($this->dir === null) {
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

        // file 模式（原子 test-and-set）：以 fopen 'x'（O_CREAT|O_EXCL）独占创建锁文件为
        // 获取原语——同一时刻仅一个进程能创建成功，消除旧实现 isLocked()→write() 两步
        // 之间的 TOCTOU 窗口（旧 rename 为无条件覆盖，两进程可同时「获得」同一把锁）。
        $this->ensureDir();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $handle = @fopen($this->path($key), 'x');
            if ($handle !== false) {
                fwrite($handle, (string) json_encode(['owner' => $owner, 'expires' => $expires]));
                fclose($handle);
                $this->dispatch(new LockAcquired($key, $owner, $ttl));

                return true;
            }

            // 文件已存在：读当前状态。
            $current = $this->read($key);
            if ($current === null) {
                @unlink($this->path($key)); // 空占位 / 损坏文件：清掉后重试一次

                continue;
            }
            // 已过期：惰性抢占，清掉后重试一次（并发抢占时仍只有一方 fopen 'x' 成功）。
            if (($current['expires'] ?? 0) <= microtime(true)) {
                @unlink($this->path($key));

                continue;
            }
            // 同一 owner 重入：刷新 TTL 视为成功
            if ($current['owner'] === $owner) {
                $this->write($key, ['owner' => $owner, 'expires' => $expires]);

                return true;
            }

            return false;
        }

        return false;
    }

    public function release(string $key, ?string $owner = null): bool
    {
        $owner = $owner ?? $this->ownerId;

        if ($this->dir === null) {
            return $this->releaseMemory($key, $owner);
        }

        // file 模式（原子 compare-and-delete）：在锁文件自身上取 LOCK_EX 后重读并校验 owner，
        // 校验通过才 unlink——修复旧实现 read→比较→clear 三步竞态（A 的锁过期被 B 抢注后，
        // A 迟到的迟到读取仍匹配旧 owner 并误删 B 的锁）。acquire 走 fopen 'x' 不受 flock
        // 影响，但其在文件存在期间的读取要么看到旧内容（判定锁定，正确），要么等我们 unlink
        // 后独占创建新锁（不被本次释放波及）。
        $file = $this->path($key);
        if (!is_file($file)) {
            return true; // 本就不持有
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return true;
        }

        @flock($handle, LOCK_EX);
        $raw = (string) stream_get_contents($handle);
        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['owner'], $data['expires'])) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($file); // 损坏 / 空占位文件：顺手清理

            return true;
        }

        if (($data['expires'] ?? 0) <= microtime(true)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($file); // 已过期：惰性清理

            return true; // 本就不持有
        }

        if ($data['owner'] !== $owner) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            return false; // owner 不匹配，拒绝释放
        }

        $result = @unlink($file);
        @flock($handle, LOCK_UN);
        fclose($handle);

        if (!$result && is_file($file)) {
            return false;
        }

        $this->dispatch(new LockReleased($key, $owner, false));

        return true;
    }

    private function releaseMemory(string $key, string $owner): bool
    {
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
                // path() 对键做了 rawurlencode（见 encodeKey），此处逆向还原逻辑键；
                // 超长哈希后缀键无法还原为原始键，返回其可读前缀段。
                $keys[] = rawurldecode(basename($file, '.lock'));
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
        $this->ensureDir();
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        // 写入失败（磁盘满 / 权限）必须显式暴露：静默假成功会让 acquire 谎报持锁。
        if (@file_put_contents($tmp, (string) json_encode($data), LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('锁状态写入失败：' . $file);
        }
    }

    /**
     * 确保 file 模式锁目录存在（acquire / write 前置）。
     */
    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建锁目录：' . $this->dir);
        }
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
        // 无损编码：rawurlencode 保证不同逻辑键不会映射到同一物理文件
        // （旧 preg_replace 把 'user:1' 与 'user_1' 压成同名锁，互斥范围被错误扩大）。
        // 超长键截断并拼内容哈希后缀，保证唯一性且不超文件名长度限制。
        return $this->dir . '/' . self::encodeKey($key) . '.lock';
    }

    /**
     * 键 → 安全文件名（双射：不同逻辑键必得不同文件名）。
     */
    private static function encodeKey(string $key): string
    {
        $encoded = rawurlencode($key);
        if (strlen($encoded) <= 200) {
            return $encoded;
        }

        return substr($encoded, 0, 160) . '-' . substr(hash('sha256', $key), 0, 32);
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
