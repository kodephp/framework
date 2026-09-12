<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Packaging\Packager;

/**
 * 系统级打包第一档：整站 → PHAR 单文件。
 *
 * 用法：
 *   php -d phar.readonly=0 kode pack:phar            构建 build/kode.phar
 *   php -d phar.readonly=0 kode pack:phar --list     干跑：只列进包清单，不构建
 *   php -d phar.readonly=0 kode pack:phar --json     JSON 输出（CI / 流水线消费）
 *
 * 退出码：0 = 成功；1 = 打包失败（含环境不满足）；2 = 参数错误。
 *
 * 只读原则：源文件不被修改，只读源 + 写 build/ 与 sys temp。
 *
 * 分层：本命令不含任何打包逻辑，只负责参数解析与输出格式化；引擎在
 * {@see Packager}（框架级、可继承）。应用想定制打包逻辑时继承 Packager 并在
 * config/packaging.php 写 'packager' 键即可，本命令无需改动。
 */
#[AsCommand(
    name: 'pack:phar',
    description: '把整个系统打成 PHAR 单文件（需 php -d phar.readonly=0）',
    usage: 'pack:phar {--json:bool} {--list:bool} {--name= : 产物文件名，默认取 config/packaging.php 的 phar_filename}',
)]
class PackPharCommand extends Command
{
    protected function handle(): int
    {
        $packer = Packager::driver();

        if ($this->flag('list')) {
            return $this->printList($packer);
        }

        $config = null;
        $name = $this->opt('name');
        if (is_string($name) && $name !== '') {
            $config = ['phar_filename' => $name];
        }

        // precheck 已在 pack() 内部执行，此处不重复——单一判定入口，避免两处口径漂移。
        $result = $packer::pack($config);

        if ($this->flag('json')) {
            $this->output->json($result);

            return empty($result['ok']) ? 1 : 0;
        }

        $this->line($packer::report($result));
        $this->line('');

        if (!empty($result['ok']) && !empty($result['dropped_files'])) {
            $this->comment(count($result['dropped_files'])
                . ' 个 autoload_files 条目因文件被排除而剔除（声明⟺实现零漂移）');
            $this->line('  校验产物：kode pack:verify ' . $result['artifact']);
            $this->line('');
        }

        if (!empty($result['ok'])) {
            return 0;
        }

        return $this->printFailure((array) $result['errors']);
    }

    /**
     * 干跑：展示将被打进包的清单，不写任何产物。
     * 用于确认白名单/黑名单配置符合预期后再真打。
     */
    private function printList(string $packer): int
    {
        $root = $packer::projectRoot();
        $paths = $packer::collect();

        if ($this->flag('json')) {
            $this->output->json(['files' => count($paths), 'paths' => $paths]);

            return 0;
        }

        $this->output->title('将进包文件清单（' . count($paths) . ' 个）');
        $this->line('项目根：' . $root);

        $rows = [];
        foreach ($paths as $rel) {
            $abs = $root . '/' . $rel;
            $rows[] = [$rel, is_file($abs) ? $packer::humanBytes((int) filesize($abs)) : '-'];
        }
        $this->table(['路径', '大小'], $rows);
        $this->line('');

        foreach ((array) $packer::config()['must_include'] as $need) {
            $ok = in_array($need, $paths, true);
            $this->line(($ok ? '✓ ' : '✗ ') . $need);
        }
        $this->line('');
        $this->line('真正构建：php -d phar.readonly=0 kode pack:phar');

        return 0;
    }

    /** 统一失败输出；返回 1。 */
    private function printFailure(array $issues): int
    {
        $this->error('打包中止：');
        foreach ($issues as $issue) {
            $this->line('  - ' . $issue);
        }
        $this->line('');
        $this->line('前置检查清单见 Packager::precheck() 的返回值与 docs/PACKAGING.md。');

        return 1;
    }
}
