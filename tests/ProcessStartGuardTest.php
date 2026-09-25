<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Process\ProcessManager;
use Kode\Framework\Process\Worker;
use Kode\Process\Process as KodeProcess;
use PHPUnit\Framework\TestCase;

/**
 * `start()` 的重复启动守卫。
 *
 * 存在的理由：pid 文件的互斥在 kode/process 里（v5.5.0 起判定、v5.5.1 起「判 + 落盘」
 * 罩在同一把 flock 里），但那份判定发生在
 * **每个守护进程自己的子进程内** —— 多槽位时父进程只是 fork 完就 wait()，
 * 子进程抛出的异常没人接，`kode process:start` 照样回「启动成功」，
 * 而系统里静静躺着两套互相看不见的守护进程。所以框架必须在**派发之前**
 * 用同一份判据（`slotStates()`）拦一次：一次说清、退出码为 1。
 * 注意这道预检挡不住**并发**（两个进程可同时看到「全没跑」），那是 process 侧 flock 的职责。
 */
final class ProcessStartGuardTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    private function tempFile(string $tag): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zz-fw-' . $tag);
        @unlink($path);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @param list<array{name: string, slot: int, pid_file: string}> $rows
     */
    private function writePid(array $rows, string $name, int $slot, int $pid): string
    {
        foreach ($rows as $row) {
            if ($row['name'] === $name && $row['slot'] === $slot) {
                file_put_contents($row['pid_file'], (string) $pid);
                $this->files[] = $row['pid_file'];

                return $row['pid_file'];
            }
        }

        self::fail("residentSlots() 里没有 {$name}:{$slot}，无法按接口口径落 pid 文件");
    }

    private function requireForking(): void
    {
        if (PHP_SAPI !== 'cli' || !extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('start() 需要 CLI + pcntl + posix 环境');
        }
    }

    public function testStartRefusesBeforeRunningOnceWorkers(): void
    {
        $this->requireForking();

        $onceFlag = $this->tempFile('once');
        $manager = new ProcessManager();
        $manager->register(new FlagOnceWorker('zz-guard-once', $onceFlag));
        $manager->register(new GuardWorker('zz-guard-busy'));

        // 本进程占住那一路的 pid 文件 = 「这一路已经在跑」。
        $this->writePid($manager->residentSlots(), 'zz-guard-busy', 0, getmypid());

        // 必须 fork 出去跑：守卫没落地时 start() 会进入监督循环永不返回，
        // 直接在测试进程里调用会把 PHPUnit 挂死。父进程只等有限时间。
        $verdict = $this->tempFile('verdict');
        $child = KodeProcess::fork(static function () use ($manager, $verdict): void {
            try {
                $manager->start();
                file_put_contents($verdict, 'started');
            } catch (\Throwable $e) {
                file_put_contents($verdict, $e::class . ':' . $e->getMessage());
            }
        });

        $seen = null;
        for ($i = 0; $i < 200; $i++) {
            if (is_file($verdict)) {
                $seen = (string) file_get_contents($verdict);
                break;
            }
            usleep(10_000);
        }

        posix_kill($child, SIGTERM);
        KodeProcess::wait($child);

        self::assertNotNull($seen, 'start() 没有立刻拒绝（子进程卡在监督循环 = 第二代守护进程已经起来了）');
        self::assertStringStartsWith('RuntimeException:', (string) $seen, '拒绝启动必须是可捕获的 RuntimeException');
        self::assertStringContainsString('zz-guard-busy:0', (string) $seen, '原因必须点名是哪一路在占用');

        // 被拒的启动不得留下任何副作用：一次性 worker 也不能顺手再跑一遍。
        self::assertFileDoesNotExist($onceFlag, '重复启动被拒之后，一次性 worker 仍然被执行了');
    }

    public function testStartProceedsWhenNothingIsRunning(): void
    {
        $this->requireForking();

        $onceFlag = $this->tempFile('free-once');
        $manager = new ProcessManager();
        $manager->register(new FlagOnceWorker('zz-guard-free', $onceFlag));

        // 只有一次性 worker：没有常驻槽位，守卫不许误伤。
        $manager->start();

        self::assertFileExists($onceFlag, '正常启动的一次性 worker 必须被执行');
    }

    /**
     * 「有个 pid 文件」不等于「在跑」。
     *
     * 上一用例挡不住过度拦截 —— 常驻槽位不存在时循环根本不执行，所以那条只证明
     * 「没注册常驻就不拦」。这里补上真正的分岔：文件在、里面的进程已经死了
     * （上一代被 SIGKILL 留下的现场），这种启动必须放行，否则一次崩溃就把系统
     * 永久锁在「起不来」的状态，而唯一的补救是让人去 /tmp 找那个文件。
     */
    public function testStalePidFileDoesNotBlockStart(): void
    {
        $this->requireForking();

        $stale = $this->deadPid();

        $onceFlag = $this->tempFile('stale-once');
        $manager = new ProcessManager();
        $manager->register(new FlagOnceWorker('zz-guard-stale-once', $onceFlag));
        $manager->register(new GuardWorker('zz-guard-stale'));

        $this->writePid($manager->residentSlots(), 'zz-guard-stale', 0, $stale);

        // 放行的一侧会进入监督循环，所以只能在子进程里跑；
        // 断言看的是**正向证据**：一次性 worker 真的被执行了（被拦则永远不出现）。
        $child = KodeProcess::fork(static function () use ($manager): void {
            $manager->start();
        });

        $proceeded = false;
        for ($i = 0; $i < 200; $i++) {
            if (is_file($onceFlag)) {
                $proceeded = true;
                break;
            }
            usleep(10_000);
        }

        posix_kill($child, SIGTERM);
        KodeProcess::wait($child);

        self::assertTrue($proceeded, "pid 文件里的进程已死（pid {$stale}）仍被判成「在跑」，启动被误拦");
    }

    /** 起一个真的子进程并回收，拿到一个「确定已死」的 pid（不靠猜大号）。 */
    private function deadPid(): int
    {
        // 用 /bin/echo 而不是 /bin/true：macOS 根本不发行 /bin/true。
        $proc = proc_open('/bin/echo zz-fw-dead', ['1' => ['file', '/dev/null', 'w']], $pipes);

        if (!is_resource($proc)) {
            self::markTestSkipped('无法启动子进程，拿不到确定已死的 pid');
        }

        $pid = proc_get_status($proc)['pid'];
        // proc_close 会 wait() 收尸；留着僵尸的话信号 0 照样成功，「已死」就判不出来。
        proc_close($proc);

        if (KodeProcess::isProcessAlive($pid)) {
            self::markTestSkipped("pid {$pid} 仍存活，本机子进程口径与预期不符");
        }

        return $pid;
    }

    /**
     * 守卫必须读框架自己那份槽位状态，而不是再写一份 pid 解析。
     *
     * 「谁在跑」这个问题在包里已经回答过一次（`slotStates()`：读文件 + 信号 0 探活，
     * 并区分「失效 pid」与「没启动过」）。start() 自己 `file_get_contents()` 一遍
     * 就是第二份判据 —— 两份迟早分岔，而分岔的方向通常是「把失效文件当成在跑」。
     */
    public function testGuardReusesSlotStatesInsteadOfParsingPidsAgain(): void
    {
        $method = new \ReflectionMethod(ProcessManager::class, 'start');
        $lines = file($method->getFileName());
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('$this->slotStates(', $body, '守卫没有复用 slotStates() 这份判据');

        foreach (['posix_kill(', 'file_get_contents(', 'isProcessAlive('] as $secondSource) {
            self::assertStringNotContainsString($secondSource, $body, "start() 里出现了第二份 pid 判据：{$secondSource}");
        }
    }
}

/**
 * 守卫用例专用：只声明身份，不执行任何业务。
 */
final class GuardWorker extends Worker
{
    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function handle(): void
    {
    }

    public function interval(): float
    {
        return 60.0;
    }
}

/**
 * 一次性 worker：把「跑过没有」写进一个文件，供断言查副作用。
 */
final class FlagOnceWorker extends Worker
{
    public function __construct(
        private readonly string $name,
        private readonly string $flag,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function handle(): void
    {
        file_put_contents($this->flag, (string) getmypid());
    }

    public function once(): bool
    {
        return true;
    }
}
