<?php

declare(strict_types=1);

namespace Kode\Framework\Process;

use Kode\Process\Daemon\Daemon;
use Kode\Process\Process as KodeProcess;
use Kode\Process\Signal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 常驻进程管理器（薄适配层，运行委托给 kode/process 的 Daemon）。
 *
 * 设计决策（v0.7.1）：真正的「fork 多进程 + Timer 周期 + 监督重生 + 优雅退出」已由
 * kode/process 的 {@see Daemon}（v5.2.31 起内置，文档明确「避开官方 worker 池回调空转陷阱」）
 * 提供。框架不再自研这套底层运行器，而是做薄适配：
 *
 *   - 注册表 / 配置解析：按 config/process.php 收集业务 Worker（register / registerClass /
 *     registerFromConfig）；
 *   - dryRun()：无 fork 同步跑一遍 handle()（CI / 无 pcntl 环境验证逻辑，Daemon 无此能力）；
 *   - start()：为每个注册的 Worker 构建并运行一个 Daemon（多 Worker 时 fork 监督子进程各跑一个）。
 *
 * 业务只需实现 {@see Worker}（name/handle 必填，interval/instances/onStart/onStop 可选），
 * 底层多进程 / 周期 / 重生 / 优雅停机全部交给 Daemon，框架零重复实现。
 */
final class ProcessManager
{
    /** @var array<string, Worker> */
    private array $workers = [];

    /** 多 Worker 启动时，fork 出的「每-worker 监督进程」pid 列表 */
    private array $children = [];

    private bool $forking = false;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * 注册一个 worker 实例（按 name() 去重）。
     */
    public function register(Worker $worker): self
    {
        $this->workers[$worker->name()] = $worker;

        return $this;
    }

    /**
     * 按类名注册（支持可选构造参数 config）。
     *
     * @param array<string, mixed> $config 传给 worker 构造函数的参数
     * @throws \InvalidArgumentException 类不存在或不是 Worker 子类
     */
    public function registerClass(string $class, array $config = []): self
    {
        return $this->register($this->createFromClass($class, $config));
    }

    /**
     * 实例化 worker 类（校验 + 按可选构造参数注入）。
     *
     * @param array<string, mixed> $config 传给 worker 构造函数的参数
     * @throws \InvalidArgumentException 类不存在或不是 Worker 子类
     */
    private function createFromClass(string $class, array $config = []): Worker
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Worker 类不存在：{$class}");
        }
        if (!is_subclass_of($class, Worker::class)) {
            throw new \InvalidArgumentException("{$class} 必须继承 " . Worker::class);
        }

        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $worker = ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0)
            ? $ref->newInstance()
            : $ref->newInstance(...$config);

        if (!$worker instanceof Worker) {
            throw new \InvalidArgumentException("{$class} 必须继承 " . Worker::class);
        }

        return $worker;
    }

    /**
     * 从配置数组批量注册。
     *
     * 支持三种写法（相互兼容）：
     *   'workers' => [ \app\process\FooWorker::class, ... ]                      // 无参
     *   'workers' => [ ['class' => ..., 'config' => [...]], ... ]              // 带构造参数
     *   'workers' => [ ['class' => ..., 'config' => [...], 'count' => 3,
     *                   'interval' => 5.0, 'once' => false, 'slots' => [0]], ] // 声明式增强
     *
     * 声明键（可选）：count=并行实例数、interval=轮询间隔秒、once=一次性执行、
     * slots=仅执行这些实例（[0] = 仅主进程槽位）。见 {@see ConfiguredWorker}。
     *
     * @param array<string, mixed> $config
     */
    public function registerFromConfig(array $config): self
    {
        $entries = $config['workers'] ?? [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $this->registerClass($entry);
            } elseif (is_array($entry) && isset($entry['class'])) {
                $worker = $this->createFromClass($entry['class'], $entry['config'] ?? []);
                $declared = array_intersect_key($entry, array_flip(['count', 'interval', 'once', 'slots']));
                if ($declared !== []) {
                    $worker = new ConfiguredWorker($worker, $declared);
                }
                $this->register($worker);
            }
        }

        return $this;
    }

    /**
     * @return array<string, Worker>
     */
    public function workers(): array
    {
        return $this->workers;
    }

    public function count(): int
    {
        return count($this->workers);
    }

    public function has(string $name): bool
    {
        return isset($this->workers[$name]);
    }

    /**
     * 声明当前环境能否真正 fork 常驻进程。
     */
    public function supportsForking(): bool
    {
        return PHP_SAPI === 'cli' && extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * 无 fork 的逻辑验证：按注册顺序同步执行每个 worker 的每个生效槽位
     * onStart() → handle(slot) → onStop() 各一次，返回已执行的 worker 名称列表
     * （每个槽位各记一次）。一次性与常驻 worker 一视同仁。
     *
     * 用于在单元测试 / CI / 无 pcntl 环境中确认 worker 业务逻辑可跑通，
     * 不依赖 kode/process 的进程模型。
     *
     * @return list<string>
     */
    public function dryRun(): array
    {
        $ran = [];
        foreach ($this->workers as $worker) {
            foreach ($this->effectiveSlots($worker) as $slot) {
                $slotWorker = new SlotWorker($worker, $slot);
                // onStart/onStop 隔离（与 runOnce 同口径）；handle 异常照常向上抛——
                // dryRun 的职责就是暴露业务逻辑问题，吞掉反而掩盖故障。
                try {
                    $slotWorker->onStart();
                } catch (\Throwable $e) {
                    error_log(sprintf('[worker:%s] dryRun onStart 异常: %s', $worker->name(), $e->getMessage()));
                }
                $slotWorker->handle();
                try {
                    $slotWorker->onStop();
                } catch (\Throwable $e) {
                    error_log(sprintf('[worker:%s] dryRun onStop 异常: %s', $worker->name(), $e->getMessage()));
                }
                $ran[] = $worker->name();
            }
        }

        return $ran;
    }

    /**
     * 计算 worker 的生效槽位列表。
     *
     * slots() 未声明（空数组）时 = 全部实例 0..instances-1；
     * 声明了则按 instances() 上限过滤越界槽位，为空时兜底 [0]。
     *
     * @return list<int>
     */
    private function effectiveSlots(Worker $worker): array
    {
        $instances = max(1, $worker->instances());
        $slots = $worker->slots();
        if ($slots === []) {
            return range(0, $instances - 1);
        }

        $filtered = array_values(array_unique(array_filter(
            $slots,
            static fn (int $s): bool => $s >= 0 && $s < $instances
        )));

        return $filtered === [] ? [0] : $filtered;
    }

    /**
     * 启动时同步执行一次性 worker：每个生效槽位 onStart() → handle(slot) → onStop() 各一次。
     */
    private function runOnce(Worker $worker): void
    {
        foreach ($this->effectiveSlots($worker) as $slot) {
            $slotWorker = new SlotWorker($worker, $slot);
            // onStart/onStop 与 handle 同等隔离（v1.0.52）：单个槽位的钩子异常不应
            // 中断 start()，导致后续 worker / 槽位全部不启动。
            try {
                $slotWorker->onStart();
            } catch (\Throwable $e) {
                error_log(sprintf('[worker:%s] once onStart 异常: %s', $worker->name(), $e->getMessage()));
            }
            try {
                $slotWorker->handle();
            } catch (\Throwable $e) {
                error_log(sprintf('[worker:%s] once handle 异常: %s', $worker->name(), $e->getMessage()));
            }
            try {
                $slotWorker->onStop();
            } catch (\Throwable $e) {
                error_log(sprintf('[worker:%s] once onStop 异常: %s', $worker->name(), $e->getMessage()));
            }
        }

        $this->logger->info('一次性 worker 已执行', ['worker' => $worker->name()]);
    }

    /**
     * 真正启动常驻进程（仅 CLI + 有 pcntl/posix 时可用）。
     *
     * 处理顺序：
     *  - once() 的一次性 worker：同步执行每个生效槽位一次即完成，不 fork；
     *  - 常驻 worker：按生效槽位拆成独立 Daemon（每个槽位一个 Daemon、由 Daemon
     *    fork 1 个 worker 子进程并按 interval() 周期调用 handle()，异常自动重生，
     *    捕获 SIGTERM/SIGINT 优雅退出）。拆分后崩溃隔离更彻底：每个槽位独立重生预算。
     *
     *  - 仅 1 个常驻槽位：直接在当前进程运行其 Daemon（当前进程即监督进程）。
     *  - 多个常驻槽位：fork 一个监督子进程各自跑一个 Daemon，主进程监督这些监督子进程。
     *
     * @param array<string, mixed> $options 预留（已不再自行管理 pid_file，交由 Daemon）
     * @throws \RuntimeException 当前环境不支持 fork / 没有注册 worker
     */
    public function start(array $options = []): void
    {
        if (!$this->supportsForking()) {
            throw new \RuntimeException(
                '常驻进程需要 CLI + ext-pcntl + ext-posix 环境，当前不可用。'
                . '可用 ProcessManager::dryRun() 验证 worker 逻辑。'
            );
        }
        if ($this->workers === []) {
            throw new \RuntimeException('没有注册任何 worker，无法启动。');
        }

        // 一次性 worker 先同步执行（启动即完成），再展开常驻槽位。
        $daemons = [];
        foreach ($this->workers as $worker) {
            if ($worker->once()) {
                $this->runOnce($worker);
                continue;
            }
            foreach ($this->effectiveSlots($worker) as $slot) {
                $daemons[] = new SlotWorker($worker, $slot);
            }
        }

        if ($daemons === []) {
            $this->logger->info('全部 worker 为一次性任务，已执行完毕，无常驻进程。');

            return;
        }

        // 单常驻槽位：直接运行 Daemon（无需额外 fork 一层监督）。
        if (count($daemons) === 1) {
            $this->runDaemon($daemons[0]);

            return;
        }

        // 多常驻槽位：fork 监督子进程，每个跑一个 Daemon；主进程监督它们。
        $this->forking = true;
        $this->children = [];

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());

        foreach ($daemons as $daemon) {
            $pid = KodeProcess::fork(
                function () use ($daemon): void {
                    // 子进程不继承父监督者的停机 handler：fork 完成到 Daemon::run() 自装信号
                    // 之间的窗口内收到 TERM 时，继承的父版 stop() 会向「兄弟监督进程」群发
                    // TERM 并忙等，造成级联误杀。重置为默认处置（仅杀当前子进程），
                    // 正式停机语义交由 Daemon 自行安装。
                    if (function_exists('pcntl_signal')) {
                        pcntl_signal(SIGTERM, SIG_DFL);
                        pcntl_signal(SIGINT, SIG_DFL);
                    }
                    $this->runDaemon($daemon);
                }
            );
            $this->children[] = $pid;
        }

        $this->children = array_values(array_unique($this->children));

        while ($this->forking && $this->children !== []) {
            $info = KodeProcess::wait(null, true);
            if ($info['pid'] > 0) {
                $this->children = array_values(array_diff($this->children, [$info['pid']]));
            }
            usleep(10000);
        }

        $this->forking = false;
    }

    /**
     * 为一个 Worker 构建并运行 Daemon。
     *
     * - onStart() 在每个 Daemon worker 子进程的首个 tick 执行一次；
     * - handle() 按 interval() 周期执行（单次异常不拖垮 worker，记日志后继续）；
     * - onStop() 在 worker 子进程退出前（register_shutdown_function）执行一次。
     */
    private function runDaemon(Worker $worker): void
    {
        $started = false;

        $task = function (...$args) use ($worker, &$started): void {
            if (!$started) {
                $worker->onStart();
                $started = true;
                // worker 子进程退出（Daemon 收到停止信号后 exit(0)）时触发 onStop()。
                register_shutdown_function(static function () use ($worker): void {
                    $worker->onStop();
                });
            }

            try {
                $worker->handle();
            } catch (\Throwable $e) {
                error_log(sprintf('[worker:%s] handle 异常: %s', $worker->name(), $e->getMessage()));
            }
        };

        Daemon::define($this->logger)
            ->task($task)
            ->every(max(0.001, $worker->interval()))
            ->workers(max(1, $worker->instances()))
            ->pidFile($this->pidFileFor($worker))
            ->run();
    }

    private function pidFileFor(Worker $worker): string
    {
        return sys_get_temp_dir() . '/kode-worker-' . $worker->name() . '.pid';
    }

    /**
     * 停止：向全部子进程（多 Worker 时为「每-worker 监督进程」）发送 SIGTERM，
     * 等待其退出（优雅回收），残留强杀。
     */
    public function stop(): void
    {
        $this->forking = false;

        foreach ($this->children as $pid) {
            @posix_kill($pid, Signal::TERM);
        }

        foreach ($this->children as $pid) {
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                if (!KodeProcess::isProcessAlive($pid)) {
                    break;
                }
                usleep(50000);
            }
            if (KodeProcess::isProcessAlive($pid)) {
                @posix_kill($pid, Signal::KILL);
            }
        }

        $this->children = [];
    }

    /**
     * @return list<int> 当前已 fork 的子进程 pid
     */
    public function children(): array
    {
        return $this->children;
    }
}
