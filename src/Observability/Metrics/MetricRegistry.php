<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Metrics;

/**
 * 指标注册表（薄封装 Counter / Gauge / Histogram）
 *
 * 单例存在于容器，业务代码通过门面 {@see \Kode\Framework\Facades\Metrics} 或
 * 助手 {@see metrics()} 取得。同名 + 同标签键的指标只创建一次（缓存），
 * 便于在请求处理的不同位置累加同一指标而不重复注册。
 *
 * 渲染输出符合 Prometheus 文本 exposition 格式（# HELP / # TYPE / 序列行），
 * 直接供 /metrics 端点返回，由 Prometheus 抓取。
 *
 * 设计立场：框架只做「内存指标收集 + 标准导出」，不做存储/推送——抓取、聚合、
 * 告警交给 Prometheus / Grafana / Alertmanager（业界标准，无需重复造轮子）。
 */
final class MetricRegistry
{
    /** @var array<string, Counter> */
    private array $counters = [];

    /** @var array<string, Gauge> */
    private array $gauges = [];

    /** @var array<string, Histogram> */
    private array $histograms = [];

    /**
     * 取/建一个计数器。
     *
     * @param array<int, string> $labelKeys
     */
    public function counter(string $name, string $help = '', array $labelKeys = []): Counter
    {
        $key = $this->key($name, $labelKeys);
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = new Counter($name, $help, $labelKeys);
        }

        return $this->counters[$key];
    }

    /**
     * 取/建一个仪表盘。
     *
     * @param array<int, string> $labelKeys
     */
    public function gauge(string $name, string $help = '', array $labelKeys = []): Gauge
    {
        $key = $this->key($name, $labelKeys);
        if (!isset($this->gauges[$key])) {
            $this->gauges[$key] = new Gauge($name, $help, $labelKeys);
        }

        return $this->gauges[$key];
    }

    /**
     * 取/建一个直方图。
     *
     * @param array<int, string> $labelKeys
     * @param array<int, float>  $buckets
     */
    public function histogram(
        string $name,
        string $help = '',
        array $labelKeys = [],
        array $buckets = Histogram::DEFAULT_BUCKETS,
    ): Histogram {
        $key = $this->key($name, $labelKeys) . '|' . implode(',', $buckets);
        if (!isset($this->histograms[$key])) {
            $this->histograms[$key] = new Histogram($name, $help, $labelKeys, $buckets);
        }

        return $this->histograms[$key];
    }

    /**
     * 渲染为 Prometheus 文本格式。
     */
    public function render(): string
    {
        $out = '';
        foreach ($this->counters as $m) {
            $out .= $m->lines();
        }
        foreach ($this->gauges as $m) {
            $out .= $m->lines();
        }
        foreach ($this->histograms as $m) {
            $out .= $m->lines();
        }

        return $out;
    }

    /**
     * 导出为结构化数组（供非 Prometheus 后端 / 测试断言）。
     *
     * @return array{text: string, counters: int, gauges: int, histograms: int}
     */
    public function snapshot(): array
    {
        return [
            'text' => $this->render(),
            'counters' => count($this->counters),
            'gauges' => count($this->gauges),
            'histograms' => count($this->histograms),
        ];
    }

    /**
     * @param array<int, string> $labelKeys
     */
    private function key(string $name, array $labelKeys): string
    {
        return $name . '|' . implode(',', $labelKeys);
    }
}
