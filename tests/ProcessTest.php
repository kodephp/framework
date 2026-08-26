<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Process\ConfiguredWorker;
use Kode\Framework\Process\ProcessManager;
use Kode\Framework\Process\Worker;
use PHPUnit\Framework\TestCase;

/**
 * 常驻进程管理器单元测试。
 *
 * 不依赖 pcntl / fork：核心用 dryRun() 同步验证 worker 逻辑可被运行器驱动，
 * 并验证 registry / 配置解析 / 守卫行为。
 */
final class ProcessTest extends TestCase
{
    protected function setUp(): void
    {
        // 每个用例前清空生命周期记录，避免用例间污染。
        LifecycleWorker::$log = [];
        SlotAwareWorker::$log = [];
        OnceWorker::$log = [];
    }

    private function makeWorker(string $name, float $interval = 1.0, int $instances = 1): Worker
    {
        return new LifecycleWorker($name, $interval, $instances);
    }

    public function testRegisterDeduplicatesByName(): void
    {
        $m = new ProcessManager();
        $m->register($this->makeWorker('a'));
        $m->register($this->makeWorker('a')); // 同名覆盖，不应 +1

        self::assertSame(1, $m->count());
        self::assertTrue($m->has('a'));
    }

    public function testRegisterClassInstantiatesAndValidates(): void
    {
        $m = new ProcessManager();
        $m->registerClass(RecordWorker::class);

        self::assertTrue($m->has('record'));
        self::assertInstanceOf(RecordWorker::class, $m->workers()['record']);
    }

    public function testRegisterClassRejectsMissingClass(): void
    {
        $m = new ProcessManager();
        $this->expectException(\InvalidArgumentException::class);
        $m->registerClass('app\process\DoesNotExistWorker');
    }

    public function testRegisterClassRejectsNonWorker(): void
    {
        $m = new ProcessManager();
        $this->expectException(\InvalidArgumentException::class);
        $m->registerClass(\stdClass::class);
    }

    public function testRegisterFromConfigStringEntries(): void
    {
        $m = new ProcessManager();
        $m->registerFromConfig([
            'workers' => [RecordWorker::class],
        ]);

        self::assertSame(1, $m->count());
        self::assertTrue($m->has('record'));
    }

    public function testRegisterFromConfigArrayEntriesWithConfig(): void
    {
        $m = new ProcessManager();
        $m->registerFromConfig([
            'workers' => [
                ['class' => ParamWorker::class, 'config' => ['name' => 'p1', 'interval' => 3.0]],
            ],
        ]);

        self::assertTrue($m->has('p1'));
        self::assertSame(3.0, $m->workers()['p1']->interval());
    }

    public function testRegisterFromConfigDeclarativeKeys(): void
    {
        $m = new ProcessManager();
        $m->registerFromConfig([
            'workers' => [
                [
                    'class'    => RecordWorker::class, // 名称固定为 record
                    'config'   => [],
                    'count'    => 4,
                    'interval' => 2.5,
                    'slots'    => [0, 2],
                    'once'     => true,
                ],
            ],
        ]);

        $w = $m->workers()['record'];
        self::assertInstanceOf(ConfiguredWorker::class, $w);
        self::assertSame(4, $w->instances());
        self::assertSame(2.5, $w->interval());
        self::assertSame([0, 2], $w->slots());
        self::assertTrue($w->once());
    }

    public function testDryRunRespectsDeclaredSlots(): void
    {
        // 声明 slots=[0]（仅主进程槽位执行）：dryRun 只跑槽位 0 一次。
        $w = new SlotAwareWorker('sa', 1, 3);
        $m = new ProcessManager();
        $m->register(new ConfiguredWorker($w, ['slots' => [0]]));

        $ran = $m->dryRun();

        self::assertSame(['sa'], $ran);
        self::assertSame(['handle:sa:0'], SlotAwareWorker::$log);
    }

    public function testDryRunRunsEverySlotWhenUnconstrained(): void
    {
        $w = new SlotAwareWorker('multi', 1, 3);
        $m = new ProcessManager();
        $m->register($w);

        $ran = $m->dryRun();

        self::assertSame(['multi', 'multi', 'multi'], $ran);
        self::assertSame(
            ['handle:multi:0', 'handle:multi:1', 'handle:multi:2'],
            SlotAwareWorker::$log
        );
    }

    public function testDryRunIgnoresOutOfRangeSlots(): void
    {
        // slots 越界（5 >= instances=2）被过滤；过滤后为空时兜底执行槽位 0。
        $w = new SlotAwareWorker('oob', 1, 2);
        $m = new ProcessManager();
        $m->register(new ConfiguredWorker($w, ['slots' => [5]]));

        $ran = $m->dryRun();

        self::assertSame(['oob'], $ran);
        self::assertSame(['handle:oob:0'], SlotAwareWorker::$log);
    }

    public function testOnceWorkerRunsOnceInDryRun(): void
    {
        $m = new ProcessManager();
        $m->register(new OnceWorker('job'));

        $ran = $m->dryRun();

        self::assertSame(['job'], $ran);
        self::assertSame(['onStart:job', 'handle:job', 'onStop:job'], OnceWorker::$log);
    }

    public function testDryRunExecutesLifecycleInOrderWithoutForking(): void
    {
        $m = new ProcessManager();
        $m->register($this->makeWorker('x'));
        $m->register($this->makeWorker('y'));

        $ran = $m->dryRun();

        self::assertSame(['x', 'y'], $ran);
        self::assertSame(
            ['onStart:x', 'handle:x', 'onStop:x', 'onStart:y', 'handle:y', 'onStop:y'],
            LifecycleWorker::$log
        );
        // dryRun 不应 fork，children 必须为空。
        self::assertSame([], $m->children());
    }

    public function testSupportsForkingReflectsEnvironment(): void
    {
        $m = new ProcessManager();
        $ok = $m->supportsForking();
        self::assertIsBool($ok);
        self::assertSame($ok, $m->supportsForking());

        if ($ok) {
            // 能 fork 的环境，dryRun 仍不 fork。
            self::assertSame([], $m->children());
        }
    }
}

/**
 * 可观测 worker：将生命周期调用记录到静态数组，供 dryRun 顺序断言。
 */
final class LifecycleWorker extends Worker
{
    public static array $log = [];

    public function __construct(
        private string $n,
        private float $iv = 1.0,
        private int $inst = 1
    ) {
    }

    public function name(): string
    {
        return $this->n;
    }

    public function handle(): void
    {
        self::$log[] = 'handle:' . $this->n;
    }

    public function interval(): float
    {
        return $this->iv;
    }

    public function instances(): int
    {
        return $this->inst;
    }

    public function onStart(): void
    {
        self::$log[] = 'onStart:' . $this->n;
    }

    public function onStop(): void
    {
        self::$log[] = 'onStop:' . $this->n;
    }
}

/**
 * 无参 worker（供 registerClass 测试）。
 */
final class RecordWorker extends Worker
{
    public function name(): string
    {
        return 'record';
    }

    public function handle(): void
    {
    }
}

/**
 * 带构造参数的 worker（供 registerFromConfig 的 config 解析测试）。
 */
final class ParamWorker extends Worker
{
    public function __construct(private string $name, private float $interval = 1.0)
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
        return $this->interval;
    }
}

/**
 * 槽位感知 worker：handle(int $slot) 记录每个槽位的执行，供 slot 语义断言。
 */
final class SlotAwareWorker extends Worker
{
    public static array $log = [];

    public function __construct(
        private string $n,
        private float $iv = 1.0,
        private int $inst = 1
    ) {
    }

    public function name(): string
    {
        return $this->n;
    }

    public function handle(int $slot = 0): void
    {
        self::$log[] = 'handle:' . $this->n . ':' . $slot;
    }

    public function interval(): float
    {
        return $this->iv;
    }

    public function instances(): int
    {
        return $this->inst;
    }
}

/**
 * 一次性 worker：once() = true，记录生命周期供 dryRun 断言。
 */
final class OnceWorker extends Worker
{
    public static array $log = [];

    public function __construct(private string $n)
    {
    }

    public function name(): string
    {
        return $this->n;
    }

    public function once(): bool
    {
        return true;
    }

    public function handle(): void
    {
        self::$log[] = 'handle:' . $this->n;
    }

    public function onStart(): void
    {
        self::$log[] = 'onStart:' . $this->n;
    }

    public function onStop(): void
    {
        self::$log[] = 'onStop:' . $this->n;
    }
}
