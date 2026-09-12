<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\ScheduledTask;
use PHPUnit\Framework\TestCase;

/**
 * 调度任务运行时生命周期验证（setEnabled / setEnabledBySource / unregister / unregisterBySource）。
 *
 * 背景：ScheduleDispatcher 此前只有「注册 + 触发」，没有回收能力。插件被暂停/卸载后，
 * 它通过 addCron() 或 #[Cron] 注册的任务**仍然会按时派发**——应用侧的状态机
 * （enabled/paused/uninstalled）与调度器的实际行为漂移，且无重启不可修复。
 *
 * 另修一个重名重复派发的隐患：底层 kode/scheduling 的 register() 只追加不去重，
 * 「卸载 → 重装 → 重扫」会让同一条任务在引擎里出现两次、到期派发两次；
 * 因此 register() 现改为按名幂等，并把闭包改为按名惰性查找当前生效记录
 * （原先闭包长期持有旧 ScheduledTask 副本，重名替换后仍会跑到旧 handler）。
 *
 * 本类验证：
 *  - withEnabled() 保持值对象不可变性（换新实例而非原地改）；
 *  - find() / bySource() 可按名与来源定位；
 *  - setEnabled() 禁用后不再到期派发，恢复后无需重启即重新调度，且同步底层 Task；
 *  - setEnabledBySource() / unregisterBySource() 一次性处置整个插件的任务；
 *  - unregister() 后既不在注册表也不再到期，runOnce() 也不再命中；
 *  - 同名重复注册只留一份记录、引擎只建一个 Task，且新 handler 生效。
 */
final class ScheduleLifecycleTest extends TestCase
{
    /** 每分钟都到期的 cron 表达式，便于在固定时刻断言「应/不应派发」。 */
    private const EVERY_MINUTE = '* * * * *';

    /** 断言用的固定时刻。 */
    private const NOW = '2026-09-12 10:00:00';

    /** 重名替换用例用：记录最后执行的是第几代 handler。 */
    private static mixed $generation = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
    }

    /**
     * 每个用例独立引导一份应用（任务注册互不污染）。
     *
     * kode/core 的 App 是进程级单例，CoreApp::boot() 重复调用会返回首个实例，
     * 故「换配置重引导」必须先清掉旧实例（与 PluginCronTest 同机制）。
     *
     * @param array<string, mixed> $overrides 启动期配置覆盖
     */
    private function boot(array $overrides = []): void
    {
        $core = new \ReflectionClass(\Kode\Core\App::class);
        $core->getProperty('instance')->setValue(null, null);

        Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT, $overrides);
    }

    private function dispatcher(): ScheduleDispatcher
    {
        return app(ScheduleDispatcher::class);
    }

    /** 构造一条带来源标签的任务描述（不注册，仅用于测试 withEnabled() 等值对象行为）。 */
    private function task(
        string $name,
        string $source = 'plugin:demo',
        bool $enabled = true,
        ?\Closure $handler = null,
    ): ScheduledTask {
        return new ScheduledTask(
            class: $handler !== null ? \Closure::class : \stdClass::class,
            method: $handler !== null ? '__invoke' : 'handle',
            expression: self::EVERY_MINUTE,
            name: $name,
            description: '测试任务',
            enabled: $enabled,
            cluster: false,
            source: $source,
            handler: $handler,
        );
    }

    /** 注册一条任务并返回实际启用数。 */
    private function add(ScheduledTask $task): int
    {
        return $this->dispatcher()->register([$task]);
    }

    /** 该任务此刻是否会被派发。 */
    private function isDue(string $name): bool
    {
        $now = new \DateTimeImmutable(self::NOW);
        foreach ($this->dispatcher()->scheduler()->dueTasks($now) as $engine) {
            if ($engine->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /** 引擎层同名任务数量（验证是否重复派发）。 */
    private function engineCount(string $name): int
    {
        $count = 0;
        foreach ($this->dispatcher()->scheduler()->tasks() as $engine) {
            if ($engine->name() === $name) {
                $count++;
            }
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // 值对象
    // ------------------------------------------------------------------

    public function testWithEnabledReturnsNewInstancePreservingEverything(): void
    {
        $original = $this->task('demo.task', 'plugin:demo', true, static function (): void {});
        $disabled = $original->withEnabled(false);

        self::assertNotSame($original, $disabled, 'withEnabled() 必须返回新实例（值对象不可变）');
        self::assertTrue($original->enabled, '原实例的 enabled 不得被改写');
        self::assertFalse($disabled->enabled);
        self::assertSame($original->name, $disabled->name);
        self::assertSame($original->source, $disabled->source);
        self::assertSame($original->expression, $disabled->expression);
        self::assertSame($original->description, $disabled->description);
        self::assertSame($original->class, $disabled->class);
        self::assertSame($original->method, $disabled->method);
        self::assertSame($original->cluster, $disabled->cluster);
        self::assertSame($original->isInline(), $disabled->isInline());
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    public function testFindReturnsRegisteredTaskAndNullForUnknown(): void
    {
        $this->add($this->task('demo.first'));
        $this->add($this->task('demo.second'));

        self::assertNull($this->dispatcher()->find('no.such.task'), '未注册名称必须返回 null');

        $found = $this->dispatcher()->find('demo.first');
        self::assertNotNull($found);
        self::assertSame('plugin:demo', $found->source);
        self::assertSame(self::EVERY_MINUTE, $found->expression);
    }

    public function testBySourceFiltersByPluginSource(): void
    {
        $this->add($this->task('app.task', 'app'));
        $this->add($this->task('demo.one', 'plugin:demo'));
        $this->add($this->task('demo.two', 'plugin:demo'));
        $this->add($this->task('other.task', 'plugin:other'));

        $names = array_map(
            static fn(ScheduledTask $t): string => $t->name,
            $this->dispatcher()->bySource('plugin:demo')
        );

        self::assertSame(['demo.one', 'demo.two'], $names);
        self::assertSame([], $this->dispatcher()->bySource('plugin:absent'));
    }

    // ------------------------------------------------------------------
    // 启停
    // ------------------------------------------------------------------

    public function testSetEnabledDisablesTaskFromBeingDue(): void
    {
        $this->add($this->task('demo.toggle'));
        self::assertTrue($this->isDue('demo.toggle'), '启用状态的任务此刻应到期');

        self::assertTrue($this->dispatcher()->setEnabled('demo.toggle', false));
        self::assertFalse($this->isDue('demo.toggle'), '禁用后不应再到期派发');
        self::assertFalse($this->dispatcher()->find('demo.toggle')?->enabled);
        self::assertFalse($this->dispatcher()->scheduler()->find('demo.toggle')?->isEnabled() ?? false,
            '底层 kode/scheduling Task 必须同步禁用，否则引擎仍会派发');
    }

    public function testSetEnabledReEnablesWithoutRestart(): void
    {
        $this->add($this->task('demo.toggle'));
        $this->dispatcher()->setEnabled('demo.toggle', false);
        self::assertFalse($this->isDue('demo.toggle'));

        self::assertTrue($this->dispatcher()->setEnabled('demo.toggle', true));
        self::assertTrue($this->isDue('demo.toggle'), '重新启用后无需重启即恢复调度');
        self::assertTrue($this->dispatcher()->find('demo.toggle')?->enabled);
    }

    public function testSetEnabledIsNoopWhenAlreadyInTargetState(): void
    {
        $this->add($this->task('demo.already-off', 'plugin:demo', false));
        $record = $this->dispatcher()->find('demo.already-off');
        self::assertNotNull($record);

        // 已是目标状态：返回 true（找到了）但不替换实例。
        self::assertTrue($this->dispatcher()->setEnabled('demo.already-off', false));
        self::assertSame($record, $this->dispatcher()->find('demo.already-off'));

        self::assertFalse($this->dispatcher()->setEnabled('no.such.task', false));
    }

    public function testSetEnabledBySourceTogglesEveryTaskOfOnePlugin(): void
    {
        $this->add($this->task('app.keep', 'app'));
        $this->add($this->task('demo.one', 'plugin:demo'));
        $this->add($this->task('demo.two', 'plugin:demo'));

        $changed = $this->dispatcher()->setEnabledBySource('plugin:demo', false);

        self::assertSame(2, $changed, '应只处置该插件自己的任务');
        self::assertFalse($this->isDue('demo.one'));
        self::assertFalse($this->isDue('demo.two'));
        self::assertTrue($this->isDue('app.keep'), '其他来源的任务不受影响');

        self::assertSame(2, $this->dispatcher()->setEnabledBySource('plugin:demo', true));
        self::assertTrue($this->isDue('demo.one'));
        self::assertTrue($this->isDue('demo.two'));
    }

    // ------------------------------------------------------------------
    // 移除
    // ------------------------------------------------------------------

    public function testUnregisterRemovesTaskAndStopsScheduling(): void
    {
        $baseline = \count($this->dispatcher()->registered());

        $this->add($this->task('demo.gone'));
        $this->add($this->task('demo.stay'));

        self::assertTrue($this->dispatcher()->unregister('demo.gone'));
        self::assertNull($this->dispatcher()->find('demo.gone'), '移除后注册表不应再查到');
        self::assertFalse($this->isDue('demo.gone'), '移除后底层引擎不得再派发');
        self::assertFalse($this->dispatcher()->runOnce('demo.gone'), '移除后 runOnce 不应命中');
        self::assertNotNull($this->dispatcher()->find('demo.stay'), '其他任务不受影响');

        self::assertFalse($this->dispatcher()->unregister('no.such.task'));
        // 基线含骨架 config/schedule.php 扫描出的主应用任务，故用相对数量断言。
        self::assertSame(
            $baseline + 1,
            \count($this->dispatcher()->registered()),
            '移除一条后注册表总数应恰好少一条'
        );
    }

    public function testUnregisterKeepsRegistrationOrderStable(): void
    {
        $this->add($this->task('demo.a'));
        $this->add($this->task('demo.b'));
        $this->add($this->task('demo.c'));

        $this->dispatcher()->unregister('demo.a');

        self::assertSame(
            ['demo.b', 'demo.c'],
            array_map(static fn(ScheduledTask $t): string => $t->name, $this->dispatcher()->bySource('plugin:demo')),
            '移除中间元素后下标必须重新连续，且相对顺序不变'
        );
    }

    public function testUnregisterBySourceRemovesWholePlugin(): void
    {
        $this->add($this->task('app.keep', 'app'));
        $this->add($this->task('demo.one', 'plugin:demo'));
        $this->add($this->task('demo.two', 'plugin:demo'));

        self::assertSame(2, $this->dispatcher()->unregisterBySource('plugin:demo'));
        self::assertSame([], $this->dispatcher()->bySource('plugin:demo'));
        self::assertFalse($this->isDue('demo.one'));
        self::assertFalse($this->isDue('demo.two'));
        self::assertTrue($this->isDue('app.keep'), '卸载单个插件不得波及主应用任务');
        self::assertSame(0, $this->dispatcher()->unregisterBySource('plugin:absent'));
    }

    // ------------------------------------------------------------------
    // 幂等注册
    // ------------------------------------------------------------------

    public function testReregisteringSameNameIsIdempotentNoDoubleFire(): void
    {
        $hits = 0;
        $task = $this->task('demo.dup', 'plugin:demo', true, static function () use (&$hits): void {
            $hits++;
        });

        self::assertSame(1, $this->add($task));
        self::assertSame(1, $this->add($task));
        self::assertSame(1, $this->add($task));

        self::assertCount(1, $this->dispatcher()->bySource('plugin:demo'),
            '同名重复注册必须只留一条记录');
        self::assertSame(1, $this->engineCount('demo.dup'),
            '底层引擎不得因重复注册出现两条同名任务（否则到期派发两次）');

        self::assertTrue($this->dispatcher()->runOnce('demo.dup'));
        self::assertTrue($this->dispatcher()->runOnce('demo.dup'));
        self::assertSame(2, $hits, '同一条任务每次 runOnce 只应执行一次');
    }

    public function testReregisteringSameNameRunsNewHandlerNotStaleClosure(): void
    {
        $this->add($this->task('demo.swap', 'plugin:demo', true, static function (): void {
            self::$generation = 1;
        }));
        self::$generation = 0;
        $this->add($this->task('demo.swap', 'plugin:demo', true, static function (): void {
            self::$generation = 2;
        }));

        self::assertTrue($this->dispatcher()->runOnce('demo.swap'));
        self::assertSame(2, self::$generation, '重名替换后必须执行新 handler（旧闭包不得长期持有旧副本）');
    }

    public function testRunOnceStillBypassesEnabledByContract(): void
    {
        $this->add($this->task('demo.off', 'plugin:demo', false));
        self::assertFalse($this->isDue('demo.off'));

        self::assertTrue($this->dispatcher()->runOnce('demo.off'),
            'runOnce 按既有契约绕过 enabled（供调试手动触发）');
    }
}
