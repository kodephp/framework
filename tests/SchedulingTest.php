<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Attributes\Reader;
use Kode\Framework\Application;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\TaskScanner;
use Kode\Framework\Tests\Fixtures\Tasks\MultiTask;
use Kode\Framework\Tests\Fixtures\Tasks\RecordTask;
use Kode\Process\Timer;
use PHPUnit\Framework\TestCase;

/**
 * 任务调度单元测试：
 *  - TaskScanner 自动发现 #[Cron]（类级 + 方法级 + 禁用）。
 *  - ScheduleDispatcher 把任务注册进 kode/process 定时器，并能手动触发一次。
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
        Timer::reset();
        RecordTask::$calls = [];
        MultiTask::$calls = [];
    }

    protected function tearDown(): void
    {
        Timer::reset();
        parent::tearDown();
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

        // 禁用任务仍被发现，但 enabled=false。
        self::assertArrayHasKey('fixture-b', $byName);
        self::assertFalse($byName['fixture-b']->enabled);
    }

    public function testDispatcherRegistersEnabledTasksIntoTimer(): void
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);
        $dispatcher = new ScheduleDispatcher();
        $n = $dispatcher->register($tasks);

        // 3 条里 1 条禁用 → 注册 2 条启用任务。
        self::assertSame(2, $n);
        self::assertSame(2, Timer::countCronJobs());
        self::assertCount(3, $dispatcher->registered());
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
}
