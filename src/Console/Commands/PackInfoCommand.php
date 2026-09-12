<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Packaging\Packager;

/**
 * 展示当前打包配置与平台就绪状态（只读，不执行任何构建）。
 *
 *   kode pack:info
 *   kode pack:info --json
 *
 * 输出内容：
 *   1. 引擎信息：驱动类、项目根、构建目录
 *   2. 产物配置：PHAR 名、二进制名、签名算法、内存下限
 *   3. 平台信息：OS、架构、PHP 版本、phar.readonly、openssl
 *   4. 前置检查：Packager::precheck() 的阻塞项列表
 *   5. 文件统计：collect() 的文件数与总大小
 *
 * 退出码：0 = 正常；1 = 参数错误。
 *
 * 只读原则：不写任何文件，不执行任何构建。
 */
#[AsCommand(
    name: 'pack:info',
    description: '展示打包配置与平台就绪状态（只读）',
    usage: 'pack:info {--json:bool}',
)]
final class PackInfoCommand extends Command
{
    protected function handle(): int
    {
        $packer = Packager::driver();
        $json = (bool) $this->flag('json', false);

        $config = $packer::config();
        $precheck = $packer::precheck();
        $platformIssues = $packer::binaryPlatformIssues();

        // 文件统计
        $root = $packer::projectRoot();
        $paths = $packer::collect();
        $fileCount = count($paths);
        $totalBytes = 0;
        foreach ($paths as $rel) {
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                $totalBytes += (int) filesize($abs);
            }
        }

        $result = [
            'engine' => [
                'driver' => $packer,
                'projectRoot' => $root,
                'buildDir' => $packer::buildDir(),
            ],
            'artifacts' => [
                'pharFilename' => $config['phar_filename'] ?? 'kode.phar',
                'binFilename' => $config['bin_filename'] ?? 'kode.bin',
                'signatureAlgorithm' => $config['signature_algorithm'] ?? \Phar::SHA256,
                'memoryLimitMb' => $config['memory_limit_mb'] ?? 512,
            ],
            'platform' => [
                'os' => PHP_OS,
                'osFamily' => PHP_OS_FAMILY,
                'arch' => php_uname('m'),
                'php' => PHP_VERSION,
                'phpVersion' => substr(PHP_VERSION, 0, 3),
                'pharReadonly' => ini_get('phar.readonly') === '1',
                'openssl' => extension_loaded('openssl'),
                'phar' => extension_loaded('phar'),
                'zip' => extension_loaded('zip'),
            ],
            'precheck' => [
                'issues' => $precheck,
                'pass' => $precheck === [],
            ],
            'binary' => [
                'platformIssues' => $platformIssues,
                'ready' => $platformIssues === [],
            ],
            'files' => [
                'count' => $fileCount,
                'totalBytes' => $totalBytes,
                'totalHuman' => $packer::humanBytes($totalBytes),
            ],
        ];

        if ($json) {
            $this->output->json($result);
            return 0;
        }

        // 文本输出
        $this->output->title('打包配置与平台状态');

        $this->line('引擎');
        $this->line("  驱动类：{$result['engine']['driver']}");
        $this->line("  项目根：{$result['engine']['projectRoot']}");
        $this->line("  构建目录：{$result['engine']['buildDir']}");
        $this->line('');

        $this->line('产物配置');
        $this->line("  PHAR 文件名：{$result['artifacts']['pharFilename']}");
        $this->line("  二进制文件名：{$result['artifacts']['binFilename']}");
        $this->line("  签名算法：" . $this->signatureName($result['artifacts']['signatureAlgorithm']));
        $this->line("  内存下限：{$result['artifacts']['memoryLimitMb']} MB");
        $this->line('');

        $this->line('平台');
        $this->line("  OS：{$result['platform']['os']} ({$result['platform']['osFamily']})");
        $this->line("  架构：{$result['platform']['arch']}");
        $this->line("  PHP：{$result['platform']['php']}");
        $this->line("  phar.readonly：" . ($result['platform']['pharReadonly'] ? '1（需 -d phar.readonly=0）' : '0'));
        $this->line("  openssl：" . ($result['platform']['openssl'] ? '✓' : '✗'));
        $this->line("  phar：" . ($result['platform']['phar'] ? '✓' : '✗'));
        $this->line("  zip：" . ($result['platform']['zip'] ? '✓' : '✗'));
        $this->line('');

        $this->line('前置检查');
        if ($result['precheck']['pass']) {
            $this->line("  ✓ 全部通过");
        } else {
            foreach ($result['precheck']['issues'] as $issue) {
                $this->line("  ✗ {$issue}");
            }
        }
        $this->line('');

        $this->line('二进制档位');
        if ($result['binary']['ready']) {
            $this->line("  ✓ 平台就绪");
        } else {
            foreach ($result['binary']['platformIssues'] as $issue) {
                $this->line("  ✗ {$issue}");
            }
        }
        $this->line('');

        $this->line('文件统计');
        $this->line("  文件数：{$result['files']['count']}");
        $this->line("  总大小：{$result['files']['totalHuman']}");
        $this->line('');

        $this->line('下一步：');
        $this->line('  php -d phar.readonly=0 kode pack:phar      构建 PHAR');
        $this->line('  php kode pack:phar --list                 干跑：只列清单');
        $this->line('  php kode pack:verify <artifact>           校验产物');

        return 0;
    }

    /** 签名算法名（数字常量 → 人类可读）。 */
    private function signatureName(int $algo): string
    {
        return match ($algo) {
            \Phar::SHA1 => 'SHA1',
            \Phar::SHA256 => 'SHA256',
            \Phar::SHA512 => 'SHA512',
            \Phar::OPENSSL => 'OpenSSL',
            default => (string) $algo,
        };
    }
}
