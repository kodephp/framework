<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

/**
 * span 批量导出完成事件（成功 / 失败均派发）。
 *
 * 监听它可做：导出计数指标、失败告警、审计日志等。
 */
final class SpansFlushed
{
    /**
     * @param int          $count   本次尝试导出的 span 数
     * @param string       $exporter 导出器名称（otlp_http / file / 自定义）
     * @param bool         $success 是否成功
     * @param string|null  $error   失败原因（成功为 null）
     */
    public function __construct(
        public readonly int $count,
        public readonly string $exporter,
        public readonly bool $success,
        public readonly ?string $error = null,
    ) {
    }
}
