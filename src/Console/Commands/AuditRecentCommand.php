<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;

/**
 * 查看最近的审计记录（开发期排查用）。
 *
 * 审计日志经 Monolog 落盘于 storage/logs/app*.log（与访问日志同一通道，
 * 消息固定为 'audit'），本命令按消息过滤并 tail 最近 N 条，便于开发期
 * 即时验证「审计是否按预期记录」「敏感字段是否已被脱敏」。
 *
 * 用法：
 *   bin/kode audit:recent                 # 最近 20 条审计
 *   bin/kode audit:recent --limit=50      # 最近 50 条
 *   bin/kode audit:recent --action=user.login   # 仅含该事件名的记录
 */
#[AsCommand(
    name: 'audit:recent',
    description: '查看最近的审计记录（开发期排查）',
    usage: 'audit:recent [--limit=N] [--action=事件名]',
)]
final class AuditRecentCommand extends Command
{
    protected function handle(): int
    {
        $limit = (int) ($this->opt('limit') ?? 20);
        if ($limit <= 0) {
            $limit = 20;
        }
        $action = $this->opt('action');

        $path = (string) (config('logging.path') ?? storage_path('logs/app.log'));
        $dir = dirname($path);
        $files = glob($dir . '/app*.log') ?: [];

        if ($files === []) {
            $this->warn('未找到审计日志文件（' . $dir . '/app*.log）。');

            return 0;
        }

        $matches = [];
        foreach ($files as $file) {
            $lines = file((string) $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                // Monolog 行形如：[...] kode.INFO: audit {...} [] —— 以消息 'audit' 过滤。
                if (!str_contains($line, ': audit ')) {
                    continue;
                }
                if ($action !== null && $action !== '' && !str_contains($line, (string) $action)) {
                    continue;
                }
                $matches[] = $line;
            }
        }

        if ($matches === []) {
            $this->info('没有匹配的审计记录。');
            return 0;
        }

        $tail = array_slice($matches, -$limit);
        foreach ($tail as $line) {
            $this->line($line);
        }

        $this->comment(sprintf('共匹配 %d 条，显示最近 %d 条。', count($matches), count($tail)));

        return 0;
    }
}
