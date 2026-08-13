<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\Framework\Config\ConfigCenter;
use Kode\Framework\Testing\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 配置中心集成测试：真实引导框架，验证 ConfigCenterServiceProvider 接线、
 * 中心值覆盖文件值、以及运行期 reload 生效。
 *
 * 注意：避免依赖全局 app() 助手（其单例跨测试类残留），统一用 bootApp() 返回的实例读取。
 */
final class ConfigCenterIntegrationTest extends TestCase
{
    // 稳定路径：ConfigCenter 在首次 boot 时把路径固化进 FileConfigSource，
    // 故 reload 测试重写的是同一文件（不能用 tempnam 每次换新路径）。
    private string $markerFile;

    private \Kode\Framework\Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markerFile = sys_get_temp_dir() . '/kode_center_itest.php';
        file_put_contents($this->markerFile, "<?php return [\n"
            . "  'center_integration_marker' => 'seeded',\n"
            . "  'app' => ['name' => 'OVERRIDDEN_BY_CENTER'],\n"
            . "];");

        // 必须在 Application::make（读取 config 文件）之前注入。
        putenv('CONFIG_CENTER_ENABLED=true');
        putenv('CONFIG_CENTER_FILE=' . $this->markerFile);

        $this->app = $this->bootApp(getcwd());
    }

    protected function tearDown(): void
    {
        putenv('CONFIG_CENTER_ENABLED');
        putenv('CONFIG_CENTER_FILE');
        @unlink($this->markerFile);
        parent::tearDown();
    }

    private function configGet(string $key, mixed $default = null): mixed
    {
        return $this->app->config()->get($key, $default);
    }

    #[RunInSeparateProcess]
    public function testProviderWiresConfigCenterAndOverridesFileConfig(): void
    {
        // 容器已 bind + alias 出 ConfigCenter 单例。
        self::assertTrue($this->app->makeService(ConfigCenter::class) instanceof ConfigCenter);

        // 中心值覆盖 config/app.php 的 name。
        self::assertSame('OVERRIDDEN_BY_CENTER', $this->configGet('app.name'));

        // 中心新增的顶层键已合并进 Config（文件配置里本来没有）。
        self::assertSame('seeded', $this->configGet('center_integration_marker'));
    }

    #[RunInSeparateProcess]
    public function testRuntimeReloadPicksUpFileChanges(): void
    {
        /** @var ConfigCenter $center */
        $center = $this->app->makeService(ConfigCenter::class);
        self::assertNotNull($center);

        // 改文件内容后 reload。
        file_put_contents($this->markerFile, "<?php return [\n"
            . "  'center_integration_marker' => 'reloaded',\n"
            . "  'app' => ['name' => 'RELOADED_BY_CENTER'],\n"
            . "];");

        $changed = $center->reload();
        self::assertContains('center_integration_marker', $changed);
        self::assertContains('app', $changed);

        self::assertSame('reloaded', $this->configGet('center_integration_marker'));
        self::assertSame('RELOADED_BY_CENTER', $this->configGet('app.name'));
        self::assertNotNull($center->lastReloadAt());
    }
}
