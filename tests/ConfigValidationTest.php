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
