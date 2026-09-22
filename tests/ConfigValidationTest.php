<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 启动期配置校验测试：config/app.required 列出的必填项缺失时应 fail-fast。
 */
final class ConfigValidationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kode_cfg_' . uniqid('', true);
        mkdir($this->tmp . '/config', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    #[RunInSeparateProcess]
    public function testBootFailsWhenRequiredConfigMissing(): void
    {
        // 完整复制框架配置，仅把 required 指向一个不存在的键，模拟「缺配置」。
        $this->copyConfig();
        $app = "<?php\nreturn array_replace(require __DIR__ . '/app.base.php', [\n"
            . "    'required' => ['app.this_key_does_not_exist'],\n"
            . "]);\n";
        // 先把原始 app.php 另存为 app.base.php，再写覆盖版。
        copy($this->tmp . '/config/app.php', $this->tmp . '/config/app.base.php');
        file_put_contents($this->tmp . '/config/app.php', $app);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/this_key_does_not_exist/');

        Application::make($this->tmp);
    }

    #[RunInSeparateProcess]
    public function testAppTimezoneIsAppliedOnBoot(): void
    {
        // config/app.timezone 曾无人读取：date()/日志时间戳恒按 PHP 内置时区（CLI 多为 UTC）。
        // 选一个与常见默认都不重合的时区，避免「碰巧相等」的假绿。
        $this->overrideAppConfig(['timezone' => 'America/New_York']);

        Application::make($this->tmp);

        $this->assertSame('America/New_York', date_default_timezone_get());
    }

    #[RunInSeparateProcess]
    public function testInvalidTimezoneIsIgnoredWithoutBreakingBoot(): void
    {
        $before = date_default_timezone_get();
        // 非法值只告警：把 error_log 落到文件，既验证告警确实发出，也不污染测试输出。
        $log = $this->tmp . '/php_error.log';
        \ini_set('error_log', $log);
        $this->overrideAppConfig(['timezone' => 'Not/AZone']);

        Application::make($this->tmp);

        $this->assertSame($before, date_default_timezone_get(), '非法时区应被忽略而不是改写成空时区');
        $this->assertStringContainsString('Not/AZone', (string) file_get_contents($log));
    }

    private function overrideAppConfig(array $overrides): void
    {
        $this->copyConfig();
        copy($this->tmp . '/config/app.php', $this->tmp . '/config/app.base.php');
        $lines = '';
        foreach ($overrides as $key => $value) {
            $lines .= "    " . var_export((string) $key, true) . ' => ' . var_export($value, true) . ",\n";
        }
        file_put_contents(
            $this->tmp . '/config/app.php',
            "<?php\nreturn array_replace(require __DIR__ . '/app.base.php', [\n" . $lines . "]);\n"
        );
    }

    private function copyConfig(): void
    {
        // 骨架夹具的 config/（仓库根自 v1.0.0 起收敛为纯内核，不再携带 config/）。
        $src = \Kode\Framework\Tests\TestCase::SKELETON_ROOT . '/config';
        foreach (glob($src . '/*.php') ?: [] as $file) {
            copy($file, $this->tmp . '/config/' . basename((string) $file));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
