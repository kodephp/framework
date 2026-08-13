<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace\Exporters;

use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Kode\Framework\Observability\Trace\OtlpMapper;
use Kode\Framework\Observability\Trace\Span;

/**
 * 文件导出器（开发 / 调试用，零依赖）。
 *
 * 把每个 span 以 OTLP JSON 一行（NDJSON）追加写入文件，无需部署 Collector 即可
 * 本地检视链路；格式与 OtlpHttpExporter 同源（OtlpMapper），可直接被 OTel 工具 ingest。
 */
final class FileSpanExporter implements SpanExporter
{
    public function __construct(private readonly string $path)
    {
    }

    #[\Override]
    public function name(): string
    {
        return 'file';
    }

    #[\Override]
    public function export(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $fp = fopen($this->path, 'a');
        if ($fp === false) {
            throw new \RuntimeException('无法打开 trace 文件：' . $this->path);
        }

        foreach ($spans as $span) {
            fwrite($fp, json_encode(OtlpMapper::mapSpan($span), JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($fp);
    }
}
