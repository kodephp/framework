<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

use Kode\Framework\Application;

/**
 * Span → OTLP 结构映射（traces 信号，JSON 编码）。
 *
 * 同时被 {@see OtlpHttpExporter}（resourceSpans 包裹）与 {@see FileSpanExporter}
 * （逐行 NDJSON）复用，保证两种导出格式一致、可直接被 OTel Collector  ingest。
 *
 * @see https://opentelemetry.io/docs/specs/otlp/#otlptrace
 */
final class OtlpMapper
{
    /**
     * 构造 OTLP /traces 完整 JSON 负载。
     *
     * @param array<int, Span>    $spans
     * @param array<string, mixed> $resource 资源属性（service.name 等）
     * @return array{resourceSpans: array<int, mixed>}
     */
    public static function resourceSpans(array $spans, array $resource): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => ['attributes' => self::keyValues($resource)],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'kode/framework',
                                'version' => Application::VERSION,
                            ],
                            'spans' => array_map(self::mapSpan(...), $spans),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 单个 span → OTLP span 对象。
     *
     * @return array<string, mixed>
     */
    public static function mapSpan(Span $span): array
    {
        return [
            'traceId' => $span->traceId,
            'spanId' => $span->spanId,
            'parentSpanId' => $span->parentSpanId ?? '',
            'name' => $span->name,
            'kind' => $span->kind,
            'startTimeUnixNano' => self::nanos($span->start),
            'endTimeUnixNano' => self::nanos($span->end ?? microtime(true)),
            'attributes' => self::keyValues($span->attributes),
            'status' => [
                'code' => $span->statusCode,
                'message' => $span->statusMessage,
            ],
            'events' => array_map(static function (array $e): array {
                return [
                    'timeUnixNano' => self::nanos($e['timestamp']),
                    'name' => $e['name'],
                    'attributes' => self::keyValues($e['attributes']),
                ];
            }, $span->events),
        ];
    }

    /**
     * 键值对 → OTLP attributes 数组。
     *
     * @param array<string, mixed> $attrs
     * @return array<int, array{key: string, value: array<string, mixed>}>
     */
    public static function keyValues(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $key => $value) {
            $out[] = ['key' => $key, 'value' => self::anyValue($value)];
        }

        return $out;
    }

    /**
     * 标量 → OTLP AnyValue。
     *
     * @return array<string, mixed>
     */
    public static function anyValue(mixed $value): array
    {
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        if (is_int($value)) {
            return ['intValue' => $value];
        }
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        return ['stringValue' => (string) $value];
    }

    /**
     * 浮点秒（microtime）→ OTLP 纳秒字符串。
     */
    public static function nanos(float $seconds): string
    {
        return (string) (int) round($seconds * 1_000_000_000);
    }
}
