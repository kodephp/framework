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
     * 常驻槽位清单（纯计算，不做 I/O）：每行 = 一路会被 {@see start()} 真正启动的守护进程。
     *
     * 存在的理由是「谁在跑」这个问题只能有一个答案。此前框架只写不读
     * （pid 文件路径由私有的 {@see pidFileFor()} 决定），应用侧于是自己编了一个
     * `/tmp/kode-daemon.pid` —— 那个路径从来没有人写，结果状态面板恒「未运行」、
     * 「启动」按钮每点一次叠加一整套守护进程、停止/重载永远失败。
     *
     * 一次性 worker（once()）不在列：它们启动即退出、从不写 pid 文件，
     * 算成常驻槽位会让面板出现一个永远跑不到的行，并对它报「发信号失败」。
     *
     * @return list<array{name: string, slot: int, pid_file: string}>
     */
    public function residentSlots(): array
    {
        $rows = [];

        foreach ($this->residentSlotPlan() as $item) {
            $rows[] = [
                'name'     => $item['worker']->name(),
                'slot'     => $item['slot'],
                'pid_file' => $this->pidFileFor($item['daemon']),
            ];
        }

        return $rows;
    }

    /**
     * 各常驻槽位的运行状态。任意 SAPI 可调（只做「读 pid 文件 + 信号 0 探活」，不 fork）。
     *
     * pid 文件只由守护进程自己在进入运行循环时创建、退出时删除，因此：
     *  - 文件缺失 = 这一路没在跑（或还没跑到写文件那一步）；
     *  - 文件内容不是数字 = 同样判不在跑，绝不去猜一个 pid 来发信号；
     *  - `started_at` 取文件 mtime = 守护进程的启动时刻，这是不依赖 `/proc` 的跨平台口径
     *    （darwin 根本没有 `/proc`）；
     *  - PID 会被操作系统复用，所以「文件在 + 进程在」理论上可能是别人的进程。
     *    这里不做二次归属判定（无跨平台手段），面板据此发信号前请核对 pid。
     *
     * 读路径**不会**删除失效的 pid 文件：清理责任属于写侧的退出逻辑，
     * 读者删文件会把一次「正在启动、尚未落 pid」的正常拉起抹成「没发生过」。
     *
     * @return list<array{name: string, slot: int, pid_file: string, pid: int|null, alive: bool, started_at: int|null}>
     */
    public function slotStates(): array
    {
        $states = [];

        foreach ($this->residentSlotPlan() as $item) {
            $file = $this->pidFileFor($item['daemon']);
            $raw  = is_file($file) ? @file_get_contents($file) : false;
            $pid  = self::pidOf($raw);

            $states[] = [
                'name'       => $item['worker']->name(),
                'slot'       => $item['slot'],
                'pid_file'   => $file,
                'pid'        => $pid,
                'alive'      => $pid !== null && KodeProcess::isProcessAlive($pid),
                'started_at' => $pid === null ? null : (@filemtime($file) ?: null),
            ];
        }

        return $states;
    }

    /**
     * 向全部「当前存活」的常驻槽位发送一个信号。
     *
     * 信号语义由 kode/process 的 Daemon 决定：`Signal::TERM` = 优雅停止，
     * `Signal::USR1` = 平滑重启该路的全部 worker。**SIGHUP 没有安装处理器**，
     * 对守护进程发 HUP 会被系统按默认处置直接终止（不是重载）。
     *
     * 目标集合恒等于 slotStates() 里 alive 的那些行，标识同为 `name:slot`，
     * 这样调用方拿到的「发给了谁」一定能和状态表对上行。
     *
     * @return array{signalled: list<string>, skipped: list<array{label: string, reason: string}>, failed: list<array{label: string, reason: string}>}
     */
    public function signalSlots(int $signal): array
    {
        $result = ['signalled' => [], 'skipped' => [], 'failed' => []];

        foreach ($this->slotStates() as $row) {
            $label = $row['name'] . ':' . $row['slot'];

            if (!$row['alive']) {
                $result['skipped'][] = ['label' => $label, 'reason' => '该槽位未在运行'];
                continue;
            }

            if (@posix_kill((int) $row['pid'], $signal)) {
                $result['signalled'][] = $label;
                continue;
            }

            // errno 只在失败时有意义（成功时不会清零，见 kode/process 的同款注释）。
            $result['failed'][] = [
                'label'  => $label,
                'reason' => '信号发送失败（errno ' . posix_get_last_error() . '，进程可能属于其他用户）',
            ];
        }

        return $result;
    }

    /**
     * pid 文件内容 → pid。只认纯数字，别的（半截写入、外部改写过）一律视为「没有 pid」。
     */
    private static function pidOf(string|false $raw): ?int
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed !== '' && ctype_digit($trimmed) ? (int) $trimmed : null;
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
     * 常驻槽位的**唯一**展开口径：`start()` 按它逐个 fork 守护进程，
     * {@see residentSlots()}/{@see slotStates()}/{@see signalSlots()} 按它找 pid 文件。
     *
     * 分成两处各展开一遍的话，「面板以为在跑的那一路」和「实际被启动的那一路」可以悄悄分叉
     * ——比如一处漏了 `once()` 过滤、一处把槽位号算错，而这两处都各自「看起来正确」。
     *
     * @return list<array{worker: Worker, slot: int, daemon: SlotWorker}>
     */
    private function residentSlotPlan(): array
    {
        $plan = [];

        foreach ($this->workers as $worker) {
            if ($worker->once()) {
                continue;
            }
            foreach ($this->effectiveSlots($worker) as $slot) {
                $plan[] = ['worker' => $worker, 'slot' => $slot, 'daemon' => new SlotWorker($worker, $slot)];
            }
        }

        return $plan;
    }

    /**
     * 启动时同步执行一次性 worker：每个生效槽位 onStart() → handle(slot) → onStop() 各一次。
     */
    private function runOnce(Worker $worker): void
    {
        foreach ($this->effectiveSlots($worker) as $slot) {
            $slotWorker = new SlotWorker($worker, $slot);
            // onStart/onStop 与 handle 同等隔离（v1.0.0）：单个槽位的钩子异常不应
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
     * @throws \RuntimeException 当前环境不支持 fork / 没有注册 worker / 已有常驻槽位在跑
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

        // 重复启动在**派发之前**拦，而且要在这里：pid 文件的互斥属于 kode/process
        // （v5.5.0 起），但那一份判定跑在每个守护进程自己的子进程里 —— 多槽位时
        // 父进程只是 fork 完就 wait()，子进程抛的异常没有任何人接，命令行照样回
        // 「启动成功」，而系统里已经躺着两套互相看不见的守护进程。
        $running = [];

        foreach ($this->slotStates() as $slot) {
            if ($slot['alive']) {
                $running[] = $slot['name'] . ':' . $slot['slot'] . '(pid ' . $slot['pid'] . ')';
            }
        }

        if ($running !== []) {
            throw new \RuntimeException(
                '常驻进程已在运行，拒绝重复启动：' . implode('、', $running) . '。'
                . '需要换 worker 注册表请先 stop 再 start；只想重载代码请发 USR1（reload）。'
            );
        }

        // 一次性 worker 先同步执行（启动即完成），再展开常驻槽位。
        foreach ($this->workers as $worker) {
            if ($worker->once()) {
                $this->runOnce($worker);
            }
        }

        // 与状态读取同一份展开（见 residentSlotPlan()）：这里 fork 的就是面板上会显示的那些路。
        $daemons = array_column($this->residentSlotPlan(), 'daemon');

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
