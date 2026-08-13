<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace\Contracts;

use Kode\Framework\Observability\Trace\Span;

/**
 * Span 导出器契约。
 *
 * 真实后端（OTLP/gRPC、protobuf、Jaeger Thrift、第三方 APM）实现本接口注入容器，
 * Tracer 即零改动使用——框架只定义「导出什么」，不绑定「怎么导出」。
 */
interface SpanExporter
{
    /**
     * 导出一批已结束的 span。
     *
     * @param array<int, Span> $spans
     *
     * @throws \Throwable 导出失败时抛出，Tracer 会捕获并保留缓冲以待重试（不阻断请求）。
     */
    public function export(array $spans): void;

    /**
     * 导出器名称（用于日志 / 事件 / 排障）。
     */
    public function name(): string;
}
