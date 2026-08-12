<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Attributes\Reader;
use Kode\Framework\Application;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\TaskScanner;
use Kode\Framework\Tests\Fixtures\Tasks\MultiTask;
use Kode\Framework\Tests\Fixtures\Tasks\RecordTask;
use PHPUnit\Framework\TestCase;

/**
 * 任务调度单元测试（执行引擎委托 kode/scheduling）：
 *  - TaskScanner 自动发现 #[Cron]（类级 + 方法级 + 禁用）。
 *  - ScheduleDispatcher 把任务注册进 kode/scheduling Scheduler，并保留 runOnce 手动触发。
 *  - Scheduler 单轮 run() 会在到期时刻执行对应任务。
 */
final class SchedulingTest extends TestCase
{
    private string $fixturesDir;

    /** 整个测试类仅启动一次应用（提供容器与 logger()）。 */
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$booted) {
            Application::make(dirname(__DIR__));
            self::$booted = true;
        }
        // 扫描依赖 class_exists 触发自动加载；夹具不在 PSR-4 内，故手动 require。
        $this->fixturesDir = __DIR__ . '/Fixtures/Tasks';
        foreach (glob($this->fixturesDir . '/*.php') ?: [] as $file) {
            require_once $file;
        }
        RecordTask::$calls = [];
        MultiTask::$calls = [];
    }

    public function testScannerDiscoversClassAndMethodLevelTasks(): void
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);

        // RecordTask 类级 1 条 + MultiTask 方法级 2 条 = 3 条。
        self::assertCount(3, $tasks);

        $byName = [];
        foreach ($tasks as $t) {
            $byName[$t->name] = $t;
        }

        self::assertArrayHasKey('fixture-record', $byName);
        self::assertSame('handle', $byName['fixture-record']->method);
        self::assertSame('0 0 * * *', $byName['fixture-record']->expression);
        self::assertTrue($byName['fixture-record']->enabled);

        self::assertArrayHasKey('fixture-a', $byName);
        self::assertSame('a', $byName['fixture-a']->method);
        self::assertSame('* * * * *', $byName['fixture-a']->expression);

        // 禁用任务仍被发现，但 enabled=false。
        self::assertArrayHasKey('fixture-b', $byName);
        self::assertFalse($byName['fixture-b']->enabled);
    }

    public function testDispatcherBuildsSchedulerWithEnabledTasks(): void
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);
        $dispatcher = new ScheduleDispatcher();
        $n = $dispatcher->register($tasks);

        // 3 条里 1 条禁用 → 注册返回 2 条启用任务。
        self::assertSame(2, $n);
        // registered() 含全部（含禁用占位）。
        self::assertCount(3, $dispatcher->registered());
        // 底层 kode/scheduling Scheduler 也含全部 3 条（禁用条目以 enabled(false) 注册）。
        self::assertCount(3, $dispatcher->scheduler()->tasks());

        // 到期判定：中午时刻 fixture-a(* * * * *) 到期；fixture-record(0 0 * * *) 不到期。
        $noon = new \DateTimeImmutable('2026-08-12 12:00:00');
        $due = $dispatcher->scheduler()->dueTasks($noon);
        $dueNames = array_map(static fn ($t) => $t->name(), $due);
        self::assertContains('fixture-a', $dueNames);
        self::assertNotContains('fixture-record', $dueNames);

        // 午夜时刻 fixture-record 也到期。
        $midnight = new \DateTimeImmutable('2026-08-12 00:00:00');
        $dueNames2 = array_map(
            static fn ($t) => $t->name(),
            $dispatcher->scheduler()->dueTasks($midnight)
        );
        self::assertContains('fixture-record', $dueNames2);
    }

    public function testRunOnceExecutesTaskMethod(): void
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);
        // 单测不启动完整应用，注入轻量 resolver（生产环境用默认全局 resolve()）。
        $dispatcher = new ScheduleDispatcher(static fn(string $class): object => new $class());
        $dispatcher->register($tasks);

        self::assertTrue($dispatcher->runOnce('fixture-record'));
        self::assertSame(['handle:default'], RecordTask::$calls);

        // 禁用的任务也可手动触发一次（runOnce 不区分 enabled）。
        self::assertTrue($dispatcher->runOnce('fixture-b'));
        self::assertSame(['b'], MultiTask::$calls);

        // 不存在的任务返回 false。
        self::assertFalse($dispatcher->runOnce('no-such-task'));
    }

    public function testSchedulerRunsDueTasksOnSinglePass(): void
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);
        $dispatcher = new ScheduleDispatcher(static fn(string $class): object => new $class());
        $dispatcher->register($tasks);

        // 中午单轮：仅 fixture-a 到期并执行；fixture-record 未到期、fixture-b 禁用。
        $report = $dispatcher->run(new \DateTimeImmutable('2026-08-12 12:00:00'));

        self::assertContains('a', MultiTask::$calls);
        self::assertNotContains('b', MultiTask::$calls);
        self::assertSame([], RecordTask::$calls);
        self::assertGreaterThanOrEqual(1, $report->succeededCount());
        self::assertSame(0, $report->failedCount());
    }
}
