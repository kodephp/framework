<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\ScheduledTask;
use Kode\Framework\Tests\Fixtures\Plugin\CronPlugin;
use Kode\Framework\Tests\Fixtures\Tasks\CronCallTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 插件定时任务（PluginManager::addCron()）端到端验证。
 *
 * 背景：PluginInterface::boot() 的注释承诺插件可「注册定时任务」，但此前 PluginManager
 * 只有 bind/alias/addRoute/addListener/addCommand/make——没有任何方法兑现这条承诺，
 * 插件定时任务只能走 #[Cron] 属性扫描，缺少命令式入口（框架侧声明与实现漂移）。
 *
 * 本类验证 addCron() 不是桩：
 *  - 三种处理器形态（[类, 方法] / '类::方法' / 闭包）都真实注册进 ScheduleDispatcher；
 *  - 闭包任务由调度器直接执行，不经容器解析（即使「任务类」是测试类本身也能跑）；
 *  - 来源标记 plugin:<name>，schedule:list 可与主应用任务区分；
 *  - 任务名冲突 / 处理器形态非法均显式抛错（不静默覆盖）；
 *  - schedule.discover_plugins 打开后插件目录的 #[Cron] 任务被 Provider 侧纳入
 *    （此前只有 bin/kode 命令支持，schedule:list 与常驻进程看不到插件任务）。
 */
final class PluginCronTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
        CronCallTask::$calls = [];
    }

    /**
     * 每个用例独立引导一份应用（任务注册互不污染）。
     *
     * kode/core 的 App 是进程级单例：CoreApp::boot() 重复调用会直接返回首个实例，
     * 因此「换配置重引导」必须先清掉旧实例（与 Testing\TestCase 的 independentApp 同机制）。
     *
     * @param array<string, mixed> $overrides 启动期配置覆盖
     */
    private function boot(array $overrides = []): void
    {
        $core = new \ReflectionClass(\Kode\Core\App::class);
        $core->getProperty('instance')->setValue(null, null);

        Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT, $overrides);
    }

    private function manager(): PluginManager
    {
        return app(PluginManager::class);
    }

    private function dispatcher(): ScheduleDispatcher
    {
        return app(ScheduleDispatcher::class);
    }

    /** 当前已注册任务（含禁用占位）。 */
    private function tasks(): array
    {
        return $this->dispatcher()->registered();
    }

    /** 手动触发一条任务（不依赖 cron 时刻，绕过 enabled）。 */
    private function runTask(string $name): bool
    {
        return $this->dispatcher()->runOnce($name);
    }

    private function find(string $name): ?ScheduledTask
    {
        foreach ($this->tasks() as $task) {
            if ($task->name === $name) {
                return $task;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 处理器形态
    // ------------------------------------------------------------------

    public function testTupleHandlerRegistersExecutablePluginTask(): void
    {
        $this->manager()->addCron(
            '0 3 * * *',
            [CronCallTask::class, 'handle'],
            'cron.tuple',
            '类方法元组（走容器解析）'
        );

        $found = $this->find('cron.tuple');

        self::assertNotNull($found, 'addCron 未把任务注册进 ScheduleDispatcher');
        self::assertSame('app', $found->source, '插件生命周期之外调用应标记为 app（不产出 plugin:unknown）');
        self::assertSame(CronCallTask::class, $found->class);
        self::assertSame('handle', $found->method);
        self::assertSame('0 3 * * *', $found->expression);
        self::assertFalse($found->isInline());

        self::assertTrue($this->runTask('cron.tuple'));
        self::assertSame(['handle'], CronCallTask::$calls);
    }

    public function testStringHandlerAcceptsClassMethodForm(): void
    {
        $this->manager()->addCron('* * * * *', CronCallTask::class . '::handle', 'cron.string');

        $found = $this->find('cron.string');

        self::assertNotNull($found, "'类::方法' 字符串形态未被识别");
        self::assertSame(CronCallTask::class, $found->class);
        self::assertSame('handle', $found->method);

        self::assertTrue($this->runTask('cron.string'));
        self::assertSame(['handle'], CronCallTask::$calls);
    }

    public function testClosureHandlerRunsWithoutContainerLookup(): void
    {
        // 内联闭包的展示 class 固定为 Closure（不可实例化）——若调度器误走容器解析，
        // 会因 new Closure() 直接致命错误，故本用例同时验证「闭包不被解析」。
        $hits = 0;
        $this->manager()->addCron('* * * * *', static function () use (&$hits): void {
            $hits++;
        }, 'cron.closure');

        $found = $this->find('cron.closure');

        self::assertNotNull($found);
        self::assertTrue($found->isInline(), '闭包任务应标记为内联处理器');
        self::assertSame('__invoke', $found->method);
        self::assertSame(\Closure::class, $found->class);
        self::assertSame(\Closure::class . '::__invoke', $found->target());

        self::assertTrue($this->runTask('cron.closure'));
        self::assertSame(1, $hits);
        self::assertSame([], CronCallTask::$calls, '闭包任务不应触碰任何任务类');
    }

    // ------------------------------------------------------------------
    // 参数与冲突
    // ------------------------------------------------------------------

    public function testDefaultNameIsPluginNameDotMethod(): void
    {
        // 通过 boot() 登记，$current 才是真实插件名（生命周期外调用会回退为 app.<方法>）。
        $this->manager()->load([CronPlugin::class]);
        $this->manager()->addCron('* * * * *', [CronCallTask::class, 'handle']);

        $found = $this->find('cron-plugin.handle');

        self::assertNotNull($found, '未指定任务名时应回退为 <插件名>.<方法名>');
        self::assertSame('CronCallTask::handle', $found->target());
    }

    public function testDuplicateNameThrows(): void
    {
        $manager = $this->manager();
        $manager->addCron('* * * * *', [CronCallTask::class, 'handle'], 'cron.dup');

        // 调度器按任务名索引（runOnce / schedule:run 均按名寻址），静默覆盖会让
        // 其中一条任务永久失效，故必须显式失败。
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('任务名');

        $manager->addCron('* * * * *', [CronCallTask::class, 'a'], 'cron.dup');
    }

    #[DataProvider('invalidHandlerProvider')]
    public function testInvalidHandlerThrows(array|string $handler): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager()->addCron('* * * * *', $handler);
    }

    /**
     * @return array<string, array{array|string}>
     */
    public static function invalidHandlerProvider(): array
    {
        return [
            '空数组' => [[]],
            '仅类名的单元素元组' => [['Kode\Framework\Tests\Fixtures\Tasks\CronCallTask']],
            '方法名为空的元组' => [['Kode\Framework\Tests\Fixtures\Tasks\CronCallTask', '']],
            '无分隔符的字符串' => ['not-a-handler'],
        ];
    }

    public function testDisabledAndClusterFlagsPropagate(): void
    {
        $manager = $this->manager();

        $disabled = $manager->addCron('0 4 * * *', [CronCallTask::class, 'b'], 'cron.disabled', enabled: false);
        $cluster = $manager->addCron('0 5 * * *', [CronCallTask::class, 'a'], 'cron.cluster', cluster: true);

        self::assertFalse($disabled->enabled);
        self::assertTrue($cluster->cluster);
        self::assertSame('cron.cluster', $cluster->name);

        // runOnce 绕过 enabled，禁用的任务仍应手动触发一次。
        self::assertTrue($this->runTask('cron.disabled'));
        self::assertSame(['b'], CronCallTask::$calls);
    }

    // ------------------------------------------------------------------
    // 插件 boot() 内命令式登记
    // ------------------------------------------------------------------

    public function testPluginBootRegistersBothCrons(): void
    {
        $this->manager()->load([CronPlugin::class]);

        $closure = $this->find('cron.plugin.closure');
        $method = $this->find('cron.plugin.method');

        self::assertNotNull($closure, '闭包式任务未从 boot() 登记进来');
        self::assertNotNull($method, '类方法式任务未从 boot() 登记进来');
        self::assertSame('plugin:cron-plugin', $closure->source);
        self::assertSame('plugin:cron-plugin', $method->source);
        self::assertSame('0 3 * * *', $method->expression);

        // boot() 期登记的任务必须可真实执行，而不只是挂在列表上。
        self::assertTrue($this->runTask('cron.plugin.method'));
        self::assertSame(['handle'], CronCallTask::$calls);
    }

    public function testPluginBootCollisionSurfacesAsRuntimeException(): void
    {
        $manager = $this->manager();
        $manager->addCron('* * * * *', [CronCallTask::class, 'a'], 'cron.plugin.closure');

        $this->expectException(\RuntimeException::class);

        $manager->load([CronPlugin::class]);
    }

    // ------------------------------------------------------------------
    // 插件任务目录自动发现（schedule.discover_plugins）
    // ------------------------------------------------------------------

    public function testDiscoverPluginsPicksUpPluginTasks(): void
    {
        // TaskScanner 靠 class_exists 加载扫描到的类；骨架内没有独立 autoloader，
        // 故发现夹具须在引导前手动加载（与 SchedulingTest 的夹具约定一致）。
        require_once \Kode\Framework\Tests\TestCase::SKELETON_ROOT
            . '/plugins/fixture_plugin/src/Tasks/PluginDueTask.php';

        $this->boot(['schedule' => [
            'paths' => ['app' => 'app/tasks'],
            'discover_plugins' => true,
            'cluster' => ['store' => '', 'ttl' => 30.0],
        ]]);

        $found = $this->find('fixture-plugin-task');

        self::assertNotNull(
            $found,
            'discover_plugins 开启后，插件 src/Tasks 下的 #[Cron] 任务应被 Provider 侧纳入调度'
        );
        self::assertSame('plugin:fixture_plugin', $found->source);

        CronCallTask::$calls = [];
        self::assertTrue($this->runTask('fixture-plugin-task'));
        self::assertSame(['plugin.due'], CronCallTask::$calls);
    }

    public function testPluginTasksNotDiscoveredByDefault(): void
    {
        $this->boot();

        self::assertNull(
            $this->find('fixture-plugin-task'),
            'discover_plugins 默认关闭时，插件任务不应进入调度（与 config/schedule.php 默认值一致）'
        );
    }
}
