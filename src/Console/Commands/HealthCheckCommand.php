<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Health\HealthChecker;

/**
 * 健康检查命令（k8s exec 探针 / CI 门禁 / 本地巡检）。
 *
 *   bin/kode console health:check              聚合巡检，打印各组件状态，degraded 以非零码退出
 *   bin/kode console health:check --ready      仅就绪语义（与 /health/ready 一致）
 *   bin/kode console health:check --json        以 JSON 输出（便于被监控/编排系统解析）
 *
 * 退出码：健康 = 0，degraded（任一 error）= 1，便于 k8s exec / CI 直接感知失败。
 */
#[AsCommand(
    name: 'health:check',
    description: '运行健康检查并打印各组件状态（degraded 时以非零码退出）',
    usage: 'health:check [--ready] [--json]',
)]
final class HealthCheckCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(HealthChecker::class)) {
            $this->warn('健康子系统未接线（HealthServiceProvider 缺失）。');

            return 0;
        }

        /** @var HealthChecker $checker */
        $checker = resolve(HealthChecker::class);
        $mode = $this->opt('ready') ? 'ready' : 'aggregate';
        $result = $checker->check($mode);

        if ($this->opt('json')) {
            $this->line(json_encode([
                'status' => $result['healthy'] ? 'ok' : 'degraded',
                'mode'   => $mode,
                'checks' => $result['checks'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $result['healthy'] ? 0 : 1;
        }

        $this->line('<info>健康检查</info> (' . $mode . ')');
        $rows = [];
        foreach ($result['checks'] as $name => $status) {
            $rows[] = [$name, $status];
        }
        $this->table(['组件', '状态'], $rows);

        if ($result['healthy']) {
            $this->info('整体状态：ok');
        } else {
            $this->error('整体状态：degraded');
        }

        return $result['healthy'] ? 0 : 1;
    }
}
