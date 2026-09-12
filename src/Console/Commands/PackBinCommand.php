<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Packaging\Packager;

/**
 * 系统级打包第二档：PHAR + 静态 PHP 运行时（micro.sfx）→ 独立可执行二进制。
 *
 * 用法：
 *   php -d phar.readonly=0 kode pack:bin            构建 build/kode.bin（需现成 phar 或先构建）
 *   kode pack:bin --sfx=/path/to/php8.micro.sfx     离线：复用本机已下载的微运行时
 *   kode pack:bin --php=8.3 --json                  指定内嵌 PHP 版本 + JSON 输出
 *
 * 退出码：0 = 成功；1 = 失败（含平台不支持）；2 = 参数错误。
 *
 * 平台约束（不可绕过，除非显式 --sfx）：micro.sfx 仅有 x86_64 Linux 构建，
 * macOS/Windows/arm64 上打包会被 binaryPlatformIssues() 拦下并说明原因。
 * 二进制不支持 Swoole 协程——需要协程请走 PHAR 档或镜像档。
 */
#[AsCommand(
    name: 'pack:bin',
    description: '把 PHAR 与静态 PHP 运行时拼成独立可执行二进制（仅 x86_64 Linux）',
    usage: 'pack:bin {--json:bool} {--sfx= : 直接指定 micro.sfx 文件（跳过下载与平台门禁）} {--php= : 覆盖内嵌 PHP 版本}',
)]
class PackBinCommand extends Command
{
    protected function handle(): int
    {
        $packer = Packager::driver();

        // 前置检查（不阻断）：先把平台不支持的原因打印出来，再让 toBin() 判定，
        // 避免用户看到「micro.sfx 下载失败」却不知真正原因是平台。
        $platformIssues = $packer::binaryPlatformIssues();
        $sfx = $this->opt('sfx');
        $sfx = is_string($sfx) && $sfx !== '' ? $sfx : null;

        if ($platformIssues !== [] && $sfx === null) {
            $this->warn('当前环境不满足二进制档位条件：');
            foreach ($platformIssues as $issue) {
                $this->line('  - ' . $issue);
            }
            $this->line('');
            $this->line('改用 PHAR 档（目标机自备 PHP）：php -d phar.readonly=0 kode pack:phar');
            $this->line('或改镜像档（任意 Linux）：docker build -t kode-app .（项目根 Dockerfile，镜像内只含单文件 PHAR）');

            return 1;
        }

        $phar = $this->resolvePhar($packer);
        if ($phar === null) {
            return 1;
        }

        $php = $this->opt('php');
        $php = is_string($php) && $php !== '' ? $php : null;

        $result = $packer::toBin($phar, $sfx, $php);

        if ($this->flag('json')) {
            $this->output->json($result);

            return empty($result['ok']) ? 1 : 0;
        }

        $this->line($packer::report($result));
        $this->line('');

        if (empty($result['ok'])) {
            $this->error('二进制打包中止：');
            foreach ((array) $result['errors'] as $error) {
                $this->line('  - ' . $error);
            }

            return 1;
        }

        $this->comment('校验：chmod +x ' . $result['artifact'] . ' && ' . $result['artifact'] . ' help');
        $this->line('');

        return 0;
    }

    /**
     * 定位用于拼接的 PHAR：优先现有产物，缺失时尝试现构建。
     *
     * @return string|null 产物绝对路径；null 表示无可用 phar
     */
    private function resolvePhar(string $packer): ?string
    {
        $artifact = $packer::buildDir() . '/' . $packer::config()['phar_filename'];

        if (is_file($artifact)) {
            $this->info('使用已有产物：' . $artifact);

            return $artifact;
        }

        $this->info('未发现 ' . $artifact . '，先构建 PHAR...');
        $result = $packer::pack();

        $this->line($packer::report($result));
        $this->line('');

        return empty($result['ok']) ? null : (string) $result['artifact'];
    }
}
