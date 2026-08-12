<?php

declare(strict_types=1);

namespace Kode\Framework\Process;

use Kode\Process\Process as KodeProcess;
use Kode\Process\Signal;
use Kode\Process\Timer;

/**
 * 常驻进程管理器（框架自建，基于 kode/process 的原语）。
 *
 * 设计取舍：kode/process 官方的 Master/Worker 池（Kode\Process::start($cb)）其
 * WorkerProcess::processTasks() 在事件循环里**从不调用用户 callback**（回调仅在外部
 * assignTask() 时触发），即官方 worker 池对「自定义周期任务」实际是空转。因此这里
 * 只用它两个定义清晰、可测试的原语：
 *   - KodeProcess::fork()   ：真正 fork 子进程；
 *   - Timer（手动驱动）      ：按 interval 触发 handle()。
 * 自行编排「fork 多个实例 + Timer 主循环 + 优雅信号」，逻辑完全可控、可单测。
 *
 * 测试/无 pcntl 环境：用 dryRun() 同步执行每个 worker 的 handle() 验证逻辑，
 * 不真正 fork；start() 在缺失 ext-pcntl 时明确抛错。
 */
final class ProcessManager
{
    /** @var array<string, Worker> */
    private array $workers = [];

    /** 已 fork 的子进程 pid 列表（仅 start() 运行时填充） */
    private array $children = [];

    private bool $forking = false;

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

        return $this->register($worker);
    }

    /**
     * 从配置数组批量注册。
     *
     * 支持两种写法：
     *   'workers' => [ App\Process\FooWorker::class, ... ]                      // 无参
     *   'workers' => [ ['class' => ..., 'config' => [...]], ... ]              // 带构造参数
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
                $this->registerClass($entry['class'], $entry['config'] ?? []);
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
     * 无 fork 的逻辑验证：按注册顺序同步执行每个 worker 的
     * onStart() → handle() → onStop() 各一次，返回已执行的 worker 名称列表。
     *
     * 用于在单元测试 / CI / 无 pcntl 环境中确认 worker 业务逻辑可跑通，
     * 不依赖进程模型。
     *
     * @return list<string>
     */
    public function dryRun(): array
    {
        $ran = [];
        foreach ($this->workers as $worker) {
            $worker->onStart();
            $worker->handle();
            $worker->onStop();
            $ran[] = $worker->name();
        }

        return $ran;
    }

    /**
     * 真正启动常驻进程（仅 CLI + 有 pcntl/posix 时可用）。
     *
     *  - 为每个注册 worker fork 出 instances() 个子进程；
     *  - 每个子进程用 Timer 按 interval() 周期调用 handle()；
     *  - 主进程等待全部子进程，捕获 SIGTERM/SIGINT 优雅转发并回收。
     *
     * @param array<string, mixed> $options 可选运行参数（预留：daemonize / pid_file 等）
     * @throws \RuntimeException 当前环境不支持 fork
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

        $this->forking = true;
        $this->children = [];

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());

        foreach ($this->workers as $worker) {
            $instances = max(1, $worker->instances());
            for ($i = 0; $i < $instances; $i++) {
                // fork 子进程跑该 worker；父进程拿到子 pid 用于后续优雅停止。
                $pid = KodeProcess::fork(
                    function () use ($worker): void {
                        $this->runWorker($worker);
                    }
                );
                $this->children[] = $pid;
            }
        }

        $this->children = array_values(array_unique($this->children));

        // 主进程：等待全部子进程退出。
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
     * 停止：向全部子进程发送 SIGTERM，等待其退出（优雅回收）。
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
            // 仍未退出则强杀。
            if (KodeProcess::isProcessAlive($pid)) {
                @posix_kill($pid, Signal::KILL);
            }
        }

        $this->children = [];
    }

    /**
     * 子进程主循环：设置标题 → onStart → Timer 周期调用 handle → 优雅退出。
     */
    private function runWorker(Worker $worker): void
    {
        KodeProcess::setProcessTitle('kode:worker:' . $worker->name());

        $alive = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$alive): void {
            $alive = false;
        });
        pcntl_signal(SIGINT, function () use (&$alive): void {
            $alive = false;
        });

        $worker->onStart();

        Timer::init();
        Timer::forever(max(0.001, $worker->interval()), function () use ($worker): void {
            try {
                $worker->handle();
            } catch (\Throwable $e) {
                // 单次失败不应拖垮整个 worker；记录后继续下一轮。
                error_log(sprintf('[worker:%s] handle 异常: %s', $worker->name(), $e->getMessage()));
            }
        });

        while ($alive) {
            Timer::tick();
            usleep(1000);
            pcntl_signal_dispatch();
        }

        $worker->onStop();
        exit(0);
    }

    /**
     * @return list<int> 当前已 fork 的子进程 pid
     */
    public function children(): array
    {
        return $this->children;
    }
}
