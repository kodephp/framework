<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Packaging\Packager;

/**
 * 产物校验（CI / 发版前门禁）。
 *
 * 用法：
 *   kode pack:verify                        校验 build/kode.phar
 *   kode pack:verify build/kode.bin --json  校验任意产物 + JSON 输出
 *
 * 退出码：0 = 校验通过；1 = 校验失败（CI 应据此阻断发版）。
 *
 * 校验项：文件存在、体积与 SHA256、PHAR 签名类型、包内文件数、
 * 桩是否含 __HALT_COMPILER()、桩是否 mapPhar 到 basename、必备入口链是否齐备。
 * 二进制档（.bin）不是 PHAR 容器，只校验体积与哈希——签名语义由 phar 档负责。
 */
#[AsCommand(
    name: 'pack:verify',
    description: '校验打包产物完整性与入口链（发版前门禁）',
    usage: 'pack:verify {file? : 产物路径，默认 build/kode.phar} {--json:bool : 输出 JSON，便于脚本消费}',
)]
class PackVerifyCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['json'];

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        $packer = Packager::driver();

        $target = $this->arg('file');
        $artifact = is_string($target) && $target !== ''
            ? $target
            : $packer::buildDir() . '/' . $packer::config()['phar_filename'];

        $result = $packer::verify($artifact);

        if ($this->flag('json')) {
            $this->output->json($result);

            return $result['ok'] ? 0 : 1;
        }

        $this->output->title('校验：' . $artifact);
        $rows = [];
        foreach ($result['checks'] as $key => $value) {
            $rows[] = [$key, $value === true ? '✓' : ($value === false ? '✗' : (string) $value)];
        }
        $this->table(['检查项', '结果'], $rows);
        $this->line('');

        if ($result['errors'] !== []) {
            $this->error('校验未通过：');
            foreach ($result['errors'] as $error) {
                $this->line('  - ' . $error);
            }
            $this->line('');

            return 1;
        }

        $this->success('校验通过：' . $artifact);

        return 0;
    }
}
