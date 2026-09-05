<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

use Kode\Process\Monitor\FileMonitor;

/**
 * 开发期热重载看门狗（serve --watch）。
 *
 * 思路（跨运行时通用的「nodemon」模式）：
 *  - 把真实的 serve 作为**子进程**启动（proc_open，继承终端 stdio）；
 *  - 父进程用 kode/process 的 {@see FileMonitor} 轮询监听约定的 PHP 目录；
 *  - 一旦检测到 .php 文件增/删/改，向子进程（master）发 SIGTERM 优雅关停 worker，
 *    再重新拉起子进程，实现「改代码即生效」且无需停掉整个服务。
 *
 * 与运行时无关：无论底层是 Native/fiber/Swoole/Workerman，serve 子进程自行管理 worker，
 * 看门狗只负责「文件变了就重启子进程」，所以 --watch 在任何环境下都可用。
 */
final class HotReloadWatcher
{
    /** 默认监听的子目录（相对项目根；不存在的会被跳过）。 */
    private const DEFAULT_DIRS = ['app', 'config', 'src', 'public', 'bin'];

    /** 监听树内默认排除的目录名。 */
    private const DEFAULT_EXCLUDE = ['vendor', '.git', 'storage', 'runtime', 'node_modules', '.workbuddy'];

    /** 轮询间隔（微秒）。 */
    private const CHECK_USLEEP = 500_000;

    /** 关停子进程后的宽限等待（微秒），让 worker 处理完在途请求。 */
    private const GRACE_USLEEP = 800_000;

    /** stopChild 的 SIGTERM 宽限上限（秒）：超时升级 SIGKILL，防 proc_close 长阻塞。 */
    private const STOP_GRACE_SECONDS = 5.0;

    /**
     * 连续快速退出的判定阈值（秒）：子进程存活不足此时长即崩溃，累计计数。
     *
     * 端口被占 / 配置错误等确定性启动失败会使每次拉起都秒崩；无此熔断看门狗会
     * 「子进程已退出，重新拉起...」无限刷屏。达到上限直接退出并指路。
     */
    private const FAST_EXIT_SECONDS = 5.0;

    /** 连续快速退出达到此次数即放弃重拉（fail-fast，不断言具体退出原因）。 */
    private const MAX_FAST_RESTARTS = 3;

    /** 当前 serve 子进程资源（供信号处理器关停）。 */
    private $currentProc = null;

    /**
     * @param string          $root        项目根目录
     * @param list<string>    $serveArgs   透传给子进程 serve 的参数（已剔除 --watch）
     * @param list<string>    $watchDirs   要监听的绝对目录（默认取 root 下的 app/config/src/public/bin）
     * @param list<string>    $excludeDirs 监听树内排除的目录名
     */
    public function __construct(
        private readonly string $root,
        private readonly array $serveArgs = [],
        private readonly array $watchDirs = [],
        private readonly array $excludeDirs = self::DEFAULT_EXCLUDE,
    ) {
    }

    /**
     * 连续快速退出计数：本次存活不足阈值则 +1（疑似秒崩），否则清零（跑稳过）。
     *
     * 纯函数，便于单测锁定熔断语义。
     */
    public static function countFastExit(int $previous, float $ageSeconds): int
    {
        return $ageSeconds < self::FAST_EXIT_SECONDS ? $previous + 1 : 0;
    }

    /**
     * 计算实际监听目录：优先用传入的 watchDirs，否则回退到 root 下默认子目录（存在的才收）。
     *
     * @return list<string>
     */
    public function resolveWatchDirs(): array
    {
        if ($this->watchDirs !== []) {
            return $this->watchDirs;
        }

        $dirs = [];
        foreach (self::DEFAULT_DIRS as $sub) {
            $abs = $this->root . '/' . $sub;
            if (is_dir($abs)) {
                $dirs[] = $abs;
            }
        }

        return $dirs;
    }

    /**
     * 启动看门狗（阻塞，直到收到 SIGINT/SIGTERM）。
     */
    public function run(): void
    {
        $dirs = $this->resolveWatchDirs();
        if ($dirs === []) {
            fwrite(STDERR, "[watch] 未找到可监听目录（app/config/src/public/bin），无法启用热重载。\n");
            exit(1);
        }

        $monitor = FileMonitor::watch($dirs)
            ->setExtensions(['.php'])
            ->setExcludeDirs($this->excludeDirs)
            ->setCheckInterval(self::CHECK_USLEEP);

        // 先建立基线（忽略首次 tick 的「全部新增」误报），避免启动时多重启一次。
        $monitor->tick();

        $this->currentProc = $this->spawn();
        $this->installSignalHandlers();

        echo sprintf(
            "[watch] 正在监听 %d 个目录的文件变化，改动后自动重启服务（Ctrl+C 停止）\n",
            count($dirs)
        );

        $spawnedAt = microtime(true);
        $fastExits = 0;
        while (!WorkerState::$stop) {
            if ($monitor->tick()) {
                echo "[watch] 检测到文件变化，重启服务...\n";
                $this->stopChild($this->currentProc);
                $this->currentProc = $this->spawn();
                $this->installSignalHandlers();
                // 有意重启：计数清零，只对「非预期的秒崩」熔断。
                $spawnedAt = microtime(true);
                $fastExits = 0;
            } elseif (!is_resource($this->currentProc) || !$this->isRunning($this->currentProc)) {
                $fastExits = self::countFastExit($fastExits, microtime(true) - $spawnedAt);
                if ($fastExits >= self::MAX_FAST_RESTARTS) {
                    fwrite(STDERR, "[watch] 子进程连续 " . self::MAX_FAST_RESTARTS . " 次启动后 "
                        . self::FAST_EXIT_SECONDS . "s 内退出（多为端口被占用或配置错误），已停止重拉。"
                        . "请检查端口占用（`kode stop --port <端口>`）或配置后重试。\n");
                    exit(1);
                }
                // 子进程意外退出：自动重新拉起。
                echo "[watch] 子进程已退出，重新拉起...\n";
                $this->currentProc = $this->spawn();
                $this->installSignalHandlers();
                $spawnedAt = microtime(true);
            }

            usleep(self::CHECK_USLEEP);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->stopChild($this->currentProc);
        echo "[watch] 已停止\n";
    }

    /**
     * 启动一个 serve 子进程，返回 proc_open 资源。
     *
     * 入口优先项目根 kode（v1.1.9 起 CLI 收敛至根），兼容仍保留 bin/kode 的老项目。
     *
     * @return resource
     */
    private function spawn()
    {
        $entry = is_file($this->root . '/kode') ? $this->root . '/kode' : $this->root . '/bin/kode';
        $cmd = [PHP_BINARY, $entry, 'start', ...$this->serveArgs];
        $quoted = implode(' ', array_map('escapeshellarg', $cmd));

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];

        $proc = @proc_open($quoted, $descriptors, $pipes, $this->root);
        if (!is_resource($proc)) {
            fwrite(STDERR, "[watch] 无法启动 serve 子进程：{$quoted}\n");
            exit(1);
        }

        return $proc;
    }

    /**
     * 优雅关停子进程（SIGTERM → 宽限轮询 → SIGKILL 升级 → 关闭）。
     *
     * v1.0.0：不再「固定睡 0.8s 后直接 proc_close」——proc_close 会阻塞到子进程真正退出，
     * 而 serve 的优雅停机宽限默认可达 30s，开发期每次保存文件都可能让看门狗卡住数十秒。
     *
     * @param resource|null $proc
     */
    private function stopChild($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }

        @proc_terminate($proc, SIGTERM);
        $deadline = microtime(true) + self::STOP_GRACE_SECONDS;
        while ($this->isRunning($proc) && microtime(true) < $deadline) {
            usleep(100_000);
        }

        if ($this->isRunning($proc)) {
            // 宽限超时：升级 SIGKILL 强杀，避免 proc_close 长阻塞卡住 watch 循环。
            @proc_terminate($proc, SIGKILL);
        }

        @proc_close($proc);
    }

    /**
     * 子进程是否仍在运行。
     *
     * @param resource $proc
     */
    private function isRunning($proc): bool
    {
        $status = @proc_get_status($proc);
        return is_array($status) && ($status['running'] ?? false);
    }

    /**
     * 注册退出信号处理：先停当前子进程再退出。
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (int $sig): void {
            $this->gracefulStop();
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    /**
     * 关停当前子进程并置退出标志（供信号处理器调用）。
     */
    private function gracefulStop(): void
    {
        $this->stopChild($this->currentProc);
        WorkerState::$stop = true;
    }
}

/**
 * 看门狗退出标志（避免闭包反复引用实例）。
 */
final class WorkerState
{
    public static bool $stop = false;
}
