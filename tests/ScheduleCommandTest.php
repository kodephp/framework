<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Attributes\Reader;
use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Core\App;
use Kode\Framework\Application;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\ScheduleListCommand;
use Kode\Framework\Console\Commands\ScheduleRunCommand;
use Kode\Framework\Console\Commands\ScheduleWorkCommand;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\TaskScanner;
use Kode\Framework\Tests\Fixtures\Tasks\MultiTask;
use Kode\Framework\Tests\Fixtures\Tasks\RecordTask;
use PHPUnit\Framework\TestCase;

/**
 * 调度命令（P0-2）端到端验证：修复「ScheduleDispatcher 生产环境从未被实例化」导致定时任务不运行。
 *  - SchedulingServiceProvider 已把 ScheduleDispatcher 接进生命周期（可被 resolve）；
 *  - schedule:run 在到期时刻真实执行任务；
 *  - schedule:list 列出已注册任务。
 */
final class ScheduleCommandTest extends TestCase
{
    private string $fixturesDir;

    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$booted) {
            Application::make(dirname(__DIR__));
            self::$booted = true;
        }
        $this->fixturesDir = __DIR__ . '/Fixtures/Tasks';
        foreach (glob($this->fixturesDir . '/*.php') ?: [] as $file) {
            require_once $file;
        }
        MultiTask::$calls = [];
        RecordTask::$calls = [];
    }

    /**
     * 把「含夹具 #[Cron] 任务」的调度器绑定进容器，模拟 SchedulingServiceProvider 的接线产物。
     */
    private function bindDispatcherWithFixtures(): ScheduleDispatcher
    {
        $tasks = (new TaskScanner(new Reader()))->scan(['app' => $this->fixturesDir]);
        $dispatcher = new ScheduleDispatcher(static fn(string $class): object => new $class());
        $dispatcher->register($tasks);

        /** @var App $app */
        $app = app();
        $app->container->instance(ScheduleDispatcher::class, $dispatcher);

        return $dispatcher;
    }

    private function execute(Command $cmd, array $argv): int
    {
        return $cmd->fire($this->inputFor($cmd, $argv), new Output(fopen('php://memory', 'w')));
    }

    private function inputFor(Command $cmd, array $argv): Input
    {
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(AsCommand::class);
        $name = 'cmd';
        $usage = '';
        if ($attrs !== []) {
            $inst = $attrs[0]->newInstance();
            $name = $inst->name;
            $usage = $inst->usage;
        }

        return new Input(array_merge([$name], $argv), $usage !== '' ? new Signature($usage) : null);
    }

    public function testDispatcherIsWiredByProvider(): void
    {
        // 启动后 SchedulingServiceProvider 已注册 ScheduleDispatcher，可被 resolve（不再静默失接）。
        self::assertInstanceOf(ScheduleDispatcher::class, resolve(ScheduleDispatcher::class));
    }

    public function testScheduleRunExecutesDueTasks(): void
    {
        $this->bindDispatcherWithFixtures();

        $code = $this->execute(new ScheduleRunCommand(), []);

        self::assertSame(0, $code);
        // fixture-a 是 * * * * *（每分钟），当前时刻必到期并执行；fixture-record 是 0 0 * * * 不到期。
        self::assertContains('a', MultiTask::$calls);
        self::assertNotContains('b', MultiTask::$calls); // fixture-b 被禁用
        self::assertSame([], RecordTask::$calls);
    }

    public function testScheduleListShowsRegisteredTasks(): void
    {
        $this->bindDispatcherWithFixtures();

        $out = fopen('php://memory', 'w');
        $code = (new ScheduleListCommand())->fire(
            $this->inputFor(new ScheduleListCommand(), []),
            new Output($out),
        );

        self::assertSame(0, $code);
        rewind($out);
        $content = (string) stream_get_contents($out);
        self::assertStringContainsString('fixture-a', $content);
        self::assertStringContainsString('fixture-record', $content);
    }

    public function testScheduleWorkCommandIsInstantiable(): void
    {
        // daemon 会阻塞进程，故仅验证可构造（真实守护由运维常驻进程运行）。
        self::assertInstanceOf(ScheduleWorkCommand::class, new ScheduleWorkCommand());
    }
}
