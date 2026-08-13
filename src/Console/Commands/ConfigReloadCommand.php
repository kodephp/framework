<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Config\ConfigCenter;
use Kode\Framework\Console\Command;

/**
 * 触发配置中心热重载。
 *
 *   bin/kode console config:center:reload
 *
 * 重新拉取各「可重载」配置源并合并进 Config，打印变化的顶层键。
 * 配合远程中心使用时：应用侧 watch 中心变更 → 写本地镜像文件 → 调本命令（或每 worker 触发）生效。
 */
#[AsCommand(
    name: 'config:center:reload',
    description: '热重载配置中心（重新拉取配置源并合并进运行时 Config）',
    usage: 'config:center:reload',
)]
final class ConfigReloadCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(ConfigCenter::class)) {
            $this->warn('配置中心未启用（config/center.php 的 center.enabled = true 才会接线）。');

            return 0;
        }

        /** @var ConfigCenter $center */
        $center = resolve(ConfigCenter::class);

        $changed = $center->reload();

        $this->info('配置中心热重载完成');
        $sources = $center->sources();
        $this->line('  源：' . ($sources === [] ? '（无）' : implode(', ', $sources)));
        $this->line($changed === []
            ? '  变化：无（配置未变更）'
            : '  变化键：' . implode(', ', $changed));

        return 0;
    }
}
