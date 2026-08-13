<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace\Exporters;

use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\OtlpMapper;
use Kode\Framework\Observability\Trace\Span;

/**
 * OTLP/HTTP JSON 导出器（零扩展依赖，纯 PHP 实现）。
 *
 * 将 span 序列化为 OTLP /v1/traces JSON，通过 HTTP POST 发送到 Collector
 * （OpenTelemetry Collector / Jaeger / Tempo 均原生支持）。
 *
 * 设计立场：不依赖 protobuf/gRPC 扩展（避免环境耦合），使用 OTLP/HTTP JSON 这一
 * 业界标准、纯 JSON 协议；如需要 gRPC/二进制高性能通道，实现 SpanExporter 注入即可。
 */
final class OtlpHttpExporter implements SpanExporter
{
    /**
     * @param array<string, string> $headers    自定义请求头（如 Authorization），空值自动忽略
     * @param array<string, mixed>  $resource   资源属性（service.name 等）
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $headers = [],
        private readonly int $timeout = 2,
        private readonly array $resource = [],
    ) {
    }

    #[\Override]
    public function name(): string
    {
        return 'otlp_http';
    }

    #[\Override]
    public function export(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        $payload = json_encode(
            OtlpMapper::resourceSpans($spans, $this->resource),
            JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) {
            throw new \RuntimeException('OTLP span 序列化失败：' . json_last_error_msg());
        }

        $this->send($payload);
    }

    private function send(string $body): void
    {
        $lines = ['Content-Type: application/json'];
        foreach ($this->headers as $key => $value) {
            if ($value !== '' && $value !== null) {
                $lines[] = $key . ': ' . $value;
            }
        }

        if (extension_loaded('curl')) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $error !== '') {
                throw new \RuntimeException('OTLP 发送失败（curl）：' . $error);
            }
            if ($code >= 400) {
                throw new \RuntimeException("OTLP 端点返回 HTTP {$code}");
            }

            return;
        }

        // 降级：无 curl 扩展时用 stream wrapper（需 allow_url_fopen）
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($this->endpoint, false, $context);
        if ($response === false) {
            throw new \RuntimeException('OTLP 发送失败（file_get_contents，确认 allow_url_fopen=On）');
        }
    }
}
