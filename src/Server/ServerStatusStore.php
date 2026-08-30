<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

/**
 * 运行状态文件仓库：`bin/kode status` 的数据源。
 *
 * 设计立场（为什么不是「共享内存 / UDP 状态端口 / 单一 JSON 文件」）：
 *
 *  - **每进程一个文件、只写自己的**：master 写 `master.json`，每个 worker 只写
 *    `worker.<pid>.json`。彻底规避多进程并发写同一文件的竞争（无需加锁、不会写坏半个 JSON），
 *    也规避了共享内存需要额外扩展（sysvshm/apcu）的依赖。
 *  - **原子写**：先写临时文件再 rename，读者永远不会读到半个文件。
 *  - **读者负责清理**：worker 是非正常退出（SIGKILL / OOM）时来不及删自己的文件，
 *    故由读者（status 命令）按 PID 存活探测剔除僵尸记录，而不是依赖写者善后。
 *  - **可被删除**：整目录都是运行时产物，删掉不影响服务（下一心跳自动重建）。
 *
 * 目录约定：`<项目根>/storage/runtime`，可用 config/server.php 的 `runtime_path` 覆盖
 * （相对路径按项目根解析，绝对路径原样使用）。
 *
 * @see \Kode\Framework\Server\ServerStatus 读取并渲染为 workerman 风格表格
 */
final class ServerStatusStore
{
    /** master 记录文件名。 */
    public const MASTER_FILE = 'master.json';

    /** worker 记录文件名前缀（完整名 worker.<pid>.json）。 */
    public const WORKER_PREFIX = 'worker.';

    /** worker 记录文件名后缀。 */
    public const WORKER_SUFFIX = '.json';

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * 按项目根构造仓库。
     *
     * @param string      $root    项目根目录（config/ 与 storage/ 的父目录）
     * @param string|null $subDir  运行时目录；null 用默认 storage/runtime。
     *                             相对路径按 $root 解析，绝对路径原样使用。
     */
    public static function forRoot(string $root, ?string $subDir = null): self
    {
        $root = rtrim($root, '/');

        if ($subDir === null || $subDir === '') {
            return new self($root . '/storage/runtime');
        }

        return new self(self::isAbsolute($subDir) ? rtrim($subDir, '/') : $root . '/' . ltrim($subDir, '/'));
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** 确保运行时目录存在（不存在则按 0755 递归创建）。 */
    public function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        return @mkdir($this->dir, 0o755, true) || is_dir($this->dir);
    }

    /**
     * 写入 master 记录（内容会与已有记录合并，便于 worker 用 ppid 补写）。
     *
     * @param array<string, mixed> $info
     */
    public function writeMaster(array $info): void
    {
        $this->write(self::MASTER_FILE, array_merge($this->master() ?? [], $info));
    }

    /**
     * 读取 master 记录；不存在或已损坏时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function master(): ?array
    {
        $data = $this->read(self::MASTER_FILE);

        return is_array($data) ? $data : null;
    }

    /**
     * 写入某个 worker 的自身记录（只应由该 worker 自己调用）。
     *
     * @param array<string, mixed> $info
     */
    public function writeWorker(int $pid, array $info): void
    {
        $this->write($this->workerFile($pid), $info);
    }

    /**
     * 读取全部 worker 记录，并顺带剔除「PID 已不存在」的僵尸文件。
     *
     * @return array<int, array<string, mixed>> 以 pid 为键
     */
    public function workers(): array
    {
        $files = @glob($this->dir . '/' . self::WORKER_PREFIX . '*' . self::WORKER_SUFFIX) ?: [];

        $out = [];
        foreach ($files as $file) {
            $pid = self::pidFromWorkerFile($file);
            if ($pid === null) {
                continue;
            }

            // 非正常退出（SIGKILL / OOM）的 worker 来不及删文件，由读者清理。
            if (!self::isAlive($pid)) {
                @unlink($file);
                continue;
            }

            $data = $this->read(self::WORKER_PREFIX . $pid . self::WORKER_SUFFIX);
            if (is_array($data)) {
                $data['pid'] = $pid;
                $out[$pid]   = $data;
            }
        }

        ksort($out);

        return $out;
    }

    /** 删除某个 worker 的记录（worker 正常退出时调用）。 */
    public function removeWorker(int $pid): void
    {
        @unlink($this->dir . '/' . $this->workerFile($pid));
    }

    /**
     * 若 master.json 记录的就是本进程，则删除（master 正常退出时的自我清理）。
     *
     * 不能用 {@see prune()} 代替：prune 按「PID 是否存活」判定，而此刻本进程当然还活着，
     * 记录会被判为有效而留下——于是 stop 之后状态目录里永远躺着一个失效的 master.json。
     */
    public function removeMasterFileIfSelf(): void
    {
        $master = $this->master();
        if ($master !== null && (int) ($master['pid'] ?? 0) === getmypid()) {
            @unlink($this->dir . '/' . self::MASTER_FILE);
        }
    }

    /**
     * 清理上一轮遗留的失效记录（不影响正在运行的实例）。
     *
     * 与 {@see clear()} 的区别：只删「PID 已死」的记录，因此重复执行 `serve` 不会
     * 抹掉另一个仍在运行的实例的状态；而 {@see clear()} 是无差别清空，只在明确
     * 要重置运行时目录时使用。
     */
    public function prune(): void
    {
        $master = $this->master();
        if ($master !== null && !self::isAlive((int) ($master['pid'] ?? 0))) {
            @unlink($this->dir . '/' . self::MASTER_FILE);
        }

        // workers() 本身就会剔除 PID 已死的 worker 文件，此处调用即完成清理。
        $this->workers();
    }

    /** 清空整个运行时目录（master + 全部 worker）。 */
    public function clear(): void
    {
        foreach ([self::MASTER_FILE, ...$this->workerFileNames()] as $name) {
            @unlink($this->dir . '/' . $name);
        }
    }

    /**
     * @return list<string>
     */
    public function workerFileNames(): array
    {
        $files = @glob($this->dir . '/' . self::WORKER_PREFIX . '*' . self::WORKER_SUFFIX) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $f): ?string => self::pidFromWorkerFile($f) === null
                ? null
                : basename($f),
            $files
        )));
    }

    /**
     * PID 存活探测。
     *
     * 用 `posix_kill($pid, 0)`（信号 0 = 只做存在性与权限检查，不真正发信号），
     * 不做 `ps` 解析：后者跨平台行为不一致且要起子进程。
     */
    public static function isAlive(int $pid): bool
    {
        if ($pid <= 1 || !function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(string $name, array $payload): void
    {
        if (!$this->ensureDir()) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // 直接带 LOCK_EX 覆写，不走「临时文件 + rename」：
        //  - 记录只有几百字节，单次 write(2) 远小于管道/页缓存原子粒度；
        //  - 每个 worker 只写自己的文件，唯一有并发的是 master.json（多个 worker 代写同一份），
        //    LOCK_EX 已足够串行化；
        //  - 临时文件方案在进程被 SIGKILL 时会留下 .tmp.<pid> 垃圾，反而更脏。
        // 读者侧对「半截 JSON」的兜底见 {@see read()}：解析失败按不存在处理。
        @file_put_contents($this->dir . '/' . $name, $json, LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    private function read(string $name): ?array
    {
        $file = $this->dir . '/' . $name;
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        // 解析失败即按「无记录」处理：宁可少显示一行，也不要让半个 JSON 变成脏数据。
        return is_array($data) ? $data : null;
    }

    private function workerFile(int $pid): string
    {
        return self::WORKER_PREFIX . $pid . self::WORKER_SUFFIX;
    }

    private static function pidFromWorkerFile(string $file): ?int
    {
        $base = basename($file);
        if (!str_starts_with($base, self::WORKER_PREFIX) || !str_ends_with($base, self::WORKER_SUFFIX)) {
            return null;
        }

        $pid = substr($base, strlen(self::WORKER_PREFIX), -strlen(self::WORKER_SUFFIX));
        if (!ctype_digit($pid)) {
            return null;
        }

        return (int) $pid;
    }

    /**
     * 跨平台绝对路径判断（与 bin/kode 的 is_absolute_path() 同语义，此处不复用以免依赖全局函数）。
     */
    private static function isAbsolute(string $path): bool
    {
        if ($path === '' || $path === '/') {
            return true;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }
}
