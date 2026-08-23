<?php

declare(strict_types=1);

namespace Kode\Framework\Idempotency;

use RuntimeException;

/**
 * 内置幂等记录后端（零依赖薄壳实现）。
 *
 * 两种模式：
 *  - memory：进程内静态表，覆盖单实例与同进程内并发（Fiber / 协程 / 同请求多次调用）；
 *  - file：文件落盘（storage_path('framework/idempotency') 或系统临时目录），覆盖同主机多进程。
 *
 * 跨主机共享去重（Redis / etcd / DB）不属于本类职责——实现 {@see IdempotencyStore} 并经
 * {@see \Kode\Framework\Providers\IdempotencyServiceProvider} 绑定即可替换，API 一致。
 *
 * 复制安全：文件写入使用 LOCK_EX 原子落盘；读取时校验到期时间戳实现惰性过期。
 */
final class StaticIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{expires: float, payload: ?string}> */
    private array $memory = [];

    /**
     * @param array<string, mixed> $config
     * @param ?string $dir file 模式的存储目录（null 时由 Provider 注入）
     */
    public function __construct(
        private readonly array $config = [],
        private readonly ?string $dir = null,
    ) {
    }

    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    public function put(string $key, int $ttl, ?string $payload = null): bool
    {
        if ($this->dir === null) {
            // memory 模式：has→write 之间无任何挂起点（PHP 协作式执行模型下天然原子）。
            if ($this->has($key)) {
                return false;
            }
            $this->write($key, ['expires' => microtime(true) + max(1, $ttl), 'payload' => $payload]);

            return true;
        }

        // file 模式（v0.8.42 修复）：先用「独占创建」原子占位（fopen 'x' = O_CREAT|O_EXCL，
        // 同一时刻仅一个进程能成功），彻底消除旧 check-then-act 竞态——
        // 旧实现 has() 与 write() 之间多进程并发会双双返回 true、互相覆盖，幂等去重失效。
        if (!$this->createExclusive($key)) {
            return false;
        }

        $this->write($key, ['expires' => microtime(true) + max(1, $ttl), 'payload' => $payload]);

        return true;
    }

    public function payload(string $key): ?string
    {
        $current = $this->read($key);
        if ($current === null) {
            return null;
        }

        return $current['payload'] ?? null;
    }

    public function attach(string $key, ?string $payload): void
    {
        $current = $this->read($key);
        if ($current === null) {
            return;
        }
        $current['payload'] = $payload;
        $this->write($key, $current);
    }

    public function forget(string $key): void
    {
        $this->clear($key);
    }

    public function ttl(string $key): ?int
    {
        $current = $this->read($key);
        if ($current === null) {
            return null;
        }
        $remaining = (int) ceil($current['expires'] - microtime(true));

        return $remaining > 0 ? $remaining : null;
    }

    public function keys(): array
    {
        if ($this->dir === null) {
            $keys = array_keys($this->memory);
        } else {
            $keys = [];
            foreach (glob($this->dir . '/*.idm') ?: [] as $file) {
                $keys[] = basename($file, '.idm');
            }
        }

        return array_values(array_filter($keys, fn (string $k): bool => $this->has($k)));
    }

    public function prune(): void
    {
        if ($this->dir === null) {
            foreach (array_keys($this->memory) as $key) {
                if (!$this->has($key)) {
                    unset($this->memory[$key]);
                }
            }
        }
        // file 模式：has() 在读取时已惰性清理过期文件，无需主动遍历
    }

    // ------------------------------------------------------------------
    // 内部：读 / 写 / 清
    // ------------------------------------------------------------------

    /**
     * @return array{expires: float, payload: ?string}|null
     */
    private function read(string $key): ?array
    {
        if ($this->dir === null) {
            $data = $this->memory[$key] ?? null;
        } else {
            $file = $this->path($key);
            if (!is_file($file)) {
                return null;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return null;
            }
        }

        if (!isset($data['expires']) || $data['expires'] <= microtime(true)) {
            if ($this->dir === null) {
                unset($this->memory[$key]);
            } else {
                @unlink($this->path($key));
            }

            return null;
        }

        return $data;
    }

    /**
     * @param array{expires: float, payload: ?string} $data
     */
    private function write(string $key, array $data): void
    {
        if ($this->dir === null) {
            $this->memory[$key] = $data;

            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建幂等存储目录：' . $this->dir);
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

    /**
     * 以独占方式创建幂等键文件（O_CREAT|O_EXCL 语义）：已存在（含并发竞争）返回 false。
     *
     * 内容随后由 {@see write()} 以 tmp + rename 原子落盘覆盖占位文件；占位到落盘之间的
     * 极窄窗口内，并发进程读取到的是空文件（json 解析失败返回 null），其 put 会因
     * fopen 'x' 失败返回 false，从而走「重放/409」路径——不会出现双 true，符合幂等语义。
     */
    private function createExclusive(string $key): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o755, true) && !is_dir($this->dir)) {
            throw new RuntimeException('无法创建幂等存储目录：' . $this->dir);
        }

        $file = $this->path($key);
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return true;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.idm';
    }
}
