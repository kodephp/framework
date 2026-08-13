<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Observability\Trace\Tracer;

/**
 * 强制导出当前执行单元缓冲的 span，并打印追踪配置状态。
 *
 *   bin/kode console tracing:flush
 *
 * 主要用于 CLI / 常驻 worker 场景（无 HTTP 请求结束自动 flush 时机）；
 * HTTP 场景由 TraceMiddleware 在根 span 结束时自动 flush。导出器未就绪时仅告警返回 0。
 */
#[AsCommand(
    name: 'tracing:flush',
    description: '强制导出缓冲的 span（OTLP），并打印追踪配置状态',
    usage: 'tracing:flush',
)]
final class TracingFlushCommand extends Command
{
    protected function handle(): int
    {
        if (!app()->container->bound(Tracer::class)) {
            $this->warn('分布式追踪未启用（config/observability.php 的 tracing.enabled = true 才会接线）。');

            return 0;
        }

        /** @var Tracer $tracer */
        $tracer = resolve(Tracer::class);

        $enabled = $tracer->isEnabled();
        $exporter = $tracer->exporterName() ?? '(未解析)';
        $ratio = (float) config('observability.tracing.sample_ratio', 1.0);
        $bufferedBefore = $tracer->buffered();

        $this->info('分布式追踪状态：');
        $this->table(
            ['项', '值'],
            [
                ['enabled', $enabled ? 'true' : 'false'],
                ['exporter', $exporter],
                ['service_name', (string) config('observability.tracing.service_name', 'kode-app')],
                ['sample_ratio', number_format($ratio, 2)],
                ['flush_on_request_end', config('observability.tracing.flush_on_request_end', true) ? 'true' : 'false'],
                ['buffered (flush 前)', (string) $bufferedBefore],
            ],
        );

        if (!$enabled) {
            $this->warn('tracing 已禁用，tracer() 返回 no-op，无 span 产生。');

            return 0;
        }

        $exported = $tracer->flush();
        if ($exported > 0) {
            $this->success("已导出 {$exported} 个 span（导出器：{$exporter}）。");
        } else {
            $this->comment('没有待导出的 span（或导出器未就绪）。');
        }

        return 0;
    }
}
