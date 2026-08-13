<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

/**
 * OTLP span status code 常量。
 *
 * @see https://opentelemetry.io/docs/specs/otel/trace/api/#status
 */
final class SpanStatus
{
    /** 未设置状态 */
    public const int UNSET = 0;

    /** 成功（正常结束） */
    public const int OK = 1;

    /** 错误（抛出异常或业务失败） */
    public const int ERROR = 2;
}
