<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

/**
 * OTLP span kind 常量（与 OpenTelemetry 规范对齐）。
 *
 * @see https://opentelemetry.io/docs/specs/otel/trace/api/#spankind
 */
final class SpanKind
{
    /** 默认内部跨度（未显式标注时） */
    public const int INTERNAL = 1;

    /** 服务端接收跨度（HTTP 入口、RPC 服务端） */
    public const int SERVER = 2;

    /** 客户端发起跨度（HTTP 出站、RPC 客户端） */
    public const int CLIENT = 3;

    /** 生产者跨度（消息队列 produce） */
    public const int PRODUCER = 4;

    /** 消费者跨度（消息队列 consume） */
    public const int CONSUMER = 5;
}
