<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Process\ProcessManager;
use Kode\Framework\Process\Worker;
use Kode\Process\Signal;
use PHPUnit\Framework\TestCase;

/**
 * 常驻槽位状态读取接口。
 *
 * 存在的理由：`start()` 会为每个常驻槽位写一份 pid 文件，但此前没有任何读回口，
 * 于是应用侧（后台「进程控制」面板）自己编了一个 `/tmp/kode-daemon.pid` ——
 * 那个路径从来没有人写，于是面板恒「未运行」、启动按钮每次都叠加一整套守护进程、
 * 停止/重载恒失败。判据必须由写 pid 文件的这一方提供，不能留第二份路径公式。
 */
final class ProcessSlotStateTest extends TestCase
{
    /** @var list<string> 本用例创建过的 pid 文件，tearDown 里逐个删。 */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        $this->createdFiles = [];
    }

    private function managerWith(Worker ...$workers): ProcessManager
    {
        $m = new ProcessManager();
        foreach ($workers as $worker) {
            $m->register($worker);
        }

        return $m;
    }

    /**
     * 借接口自己给的路径落盘，测试不重写一遍文件名公式。
     */
    private function writePid(ProcessManager $m, string $name, int $slot, int $pid): string
    {
        $rows = $m->residentSlots();
        $file = null;
        foreach ($rows as $row) {
            if ($row['name'] === $name && $row['slot'] === $slot) {
                $file = $row['pid_file'];
                break;
            }
        }
        self::assertNotNull($file, "residentSlots() 里没有 {$name}:{$slot}，无法按接口口径落 pid 文件");

        file_put_contents($file, (string) $pid);
        $this->createdFiles[] = $file;

        return $file;
    }

    /** 起一个真的子进程并回收，拿到一个「确定已死」的 pid（不靠猜大号）。 */
    private function deadPid(): int
    {
        // 用 /bin/echo 而不是 /bin/true：macOS 根本不发行 /bin/true。
        $proc = proc_open('/bin/echo zz-dead', ['1' => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::markTestSkipped('无法启动子进程，拿不到确定已死的 pid');
        }
        $pid = proc_get_status($proc)['pid'];
        // proc_close 会 wait() 收尸；留着僵尸的话信号 0 照样成功，「已死」就判不出来。
        proc_close($proc);

        if (\Kode\Process\Process::isProcessAlive($pid)) {
            self::markTestSkipped("pid {$pid} 仍存活，本机子进程口径与预期不符");
        }

        return $pid;
    }

    public function testResidentSlotsEnumerateEverySlotAndSkipOnceWorkers(): void
    {
        $m = $this->managerWith(new StateWorker('zz-multi', instances: 2), new StateWorker('zz-once', once: true));

        $rows = $m->residentSlots();

        // 一次性 worker 启动即退出、不写 pid 文件；把它算进「常驻槽位」会让面板
        // 永远显示一个跑不到的槽位，且 stop 会对它报「发信号失败」。
        self::assertSame([['zz-multi', 0], ['zz-multi', 1]], array_map(
            static fn (array $r): array => [$r['name'], $r['slot']],
            $rows
        ));

        // 每个槽位必须独占一份 pid 文件：两份 Daemon 写同一个文件时，
        // 「按 pid 文件判在不在跑」只对最后写入者成立，另一路守护进程永久隐形。
        $files = array_column($rows, 'pid_file');
        self::assertSame(count($files), count(array_unique($files)));
        self::assertSame(2, count($files));
        self::assertStringEndsWith(':0.pid', $files[0]);
        self::assertStringEndsWith(':1.pid', $files[1]);
    }

    public function testEmptyRegistryHasNoSlots(): void
    {
        self::assertSame([], (new ProcessManager())->residentSlots());
        self::assertSame([], (new ProcessManager())->slotStates());
    }

    public function testSlotStateReportsLivePidAndItsStartTime(): void
    {
        $m = $this->managerWith(new StateWorker('zz-live'));

        $file = $this->writePid($m, 'zz-live', 0, getmypid());

        [$row] = $m->slotStates();

        self::assertSame(getmypid(), $row['pid']);
        self::assertTrue($row['alive'], 'pid 文件里是本进程，却判成未存活');
        // pid 文件由 Daemon 进入 run() 时创建，它的 mtime 就是守护进程的启动时刻
        // —— 这是不依赖 /proc 的唯一跨平台口径（darwin 没有 /proc）。
        self::assertSame(filemtime($file), $row['started_at']);
    }

    public function testMissingPidFileIsStoppedNotAnError(): void
    {
        $m = $this->managerWith(new StateWorker('zz-absent'));

        [$row] = $m->slotStates();

        self::assertNull($row['pid']);
        self::assertFalse($row['alive']);
        self::assertNull($row['started_at']);
    }

    public function testStalePidFileIsStoppedAndLeftForItsOwner(): void
    {
        $m = $this->managerWith(new StateWorker('zz-stale'));

        $file = $this->writePid($m, 'zz-stale', 0, $this->deadPid());

        [$row] = $m->slotStates();

        self::assertFalse($row['alive']);
        // 读路径不许删别人的 pid 文件：守护进程可能正在「fork 完成、尚未写文件」的窗口里，
        // 删掉等于把一次正常启动判成没发生。清理由写侧的退出逻辑负责。
        self::assertFileExists($file);
    }

    public function testGarbagePidFileIsStoppedNotFatal(): void
    {
        $m = $this->managerWith(new StateWorker('zz-garbage'));

        // 半截文件 / 被别的程序写过：只能判「未存活」，不能让 (int) 转换把垃圾读成
        // 一个像样的 pid 再拿去发信号。
        $file = $this->writePid($m, 'zz-garbage', 0, 0);
        file_put_contents($file, 'not-a-pid');

        [$row] = $m->slotStates();

        self::assertNull($row['pid']);
        self::assertFalse($row['alive']);
    }

    public function testSignalSlotsTargetsOnlyLiveSlots(): void
    {
        $m = $this->managerWith(new StateWorker('zz-sig-live'), new StateWorker('zz-sig-dead'));

        $this->writePid($m, 'zz-sig-live', 0, getmypid());
        $this->writePid($m, 'zz-sig-dead', 0, $this->deadPid());

        // SIGCONT 对未暂停的进程是空操作：用真信号验「发给谁」，不碰 TERM 以免自杀。
        $result = $m->signalSlots(Signal::CONT);

        self::assertSame(['zz-sig-live:0'], $result['signalled']);
        self::assertSame(['zz-sig-dead:0'], array_column($result['skipped'], 'label'));
        self::assertSame([], $result['failed']);
        self::assertTrue(\Kode\Process\Process::isProcessAlive(getmypid()));
    }

    public function testSignalSlotsOnEmptyRegistryIsANoOp(): void
    {
        self::assertSame(
            ['signalled' => [], 'skipped' => [], 'failed' => []],
            (new ProcessManager())->signalSlots(Signal::CONT)
        );
    }

    public function testSlotLabelsAreTheSameStringsUsedBySignalResults(): void
    {
        // 状态与发信号必须回同一套标识，否则前端拿到「signalled: [a:0]」却对不上
        // slotStates 里的行，运维就不知道刚才是不是真的动过这一路。
        $m = $this->managerWith(new StateWorker('zz-label'));

        $this->writePid($m, 'zz-label', 0, getmypid());

        $states = $m->slotStates();
        $result = $m->signalSlots(Signal::CONT);

        self::assertSame(
            $states[0]['name'] . ':' . $states[0]['slot'],
            $result['signalled'][0]
        );
    }
}

/**
 * 状态用例专用 worker：只声明身份与实例数，不执行任何业务。
 */
final class StateWorker extends Worker
{
    public function __construct(
        private readonly string $name,
        private readonly int $instances = 1,
        private readonly bool $once = false,
    ) {
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

    public function instances(): int
    {
        return $this->instances;
    }

    public function once(): bool
    {
        return $this->once;
    }
}
