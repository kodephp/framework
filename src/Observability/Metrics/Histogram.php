<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Metrics;

/**
 * 直方图（Prometheus histogram 语义）
 *
 * 用于观测「分布型」指标（请求时延、响应体大小等）：记录观察值后自动落入分桶，
 * 输出 `_bucket{le=...}` / `_sum` / `_count`，配合 Grafana 的 histogram_quantile
 * 即可算 P50/P95/P99。标签用法同 {@see Counter}。
 *
 * 默认分桶上界（秒）：1ms ~ 10s。可用 config 覆盖。
 */
final class Histogram
{
    /**
     * 默认分桶上界（秒）。
     *
     * @var array<int, float>
     */
    public const array DEFAULT_BUCKETS = [
        0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10,
    ];

    /**
     * @param string             $name
     * @param string             $help
     * @param array<int, string> $labelKeys
     * @param array<int, float>  $buckets
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly array $labelKeys = [],
        public readonly array $buckets = self::DEFAULT_BUCKETS,
    ) {
    }

    /** @var array<string, array{count:int, sum:float, buckets:array<int,int>}> */
    private array $series = [];

    /**
     * @param array<string, string|int> $labelValues
     */
    public function with(array $labelValues): BoundHistogram
    {
        return new BoundHistogram($this, $labelValues);
    }

    /**
     * 无标签便捷观察（等价于 with([])->observe()）。
     */
    public function observe(float $value): void
    {
        $this->observeBy([], $value);
    }

    /**
     * 观察一个值（内部用）。
     *
     * @param array<string, string|int> $labelValues
     */
    public function observeBy(array $labelValues, float $value): void
    {        $key = $this->serialize($labelValues);
        if (!isset($this->series[$key])) {
            $this->series[$key] = [
                'count' => 0,
                'sum' => 0.0,
                'buckets' => array_fill(0, count($this->buckets), 0),
            ];
        }
        $s = &$this->series[$key];
        $s['count']++;
        $s['sum'] += $value;
        // 仅落入「最小匹配桶」（即 (prev_upper, upper] 区间），避免重复计数。
        foreach ($this->buckets as $i => $upper) {
            if ($value <= $upper) {
                $s['buckets'][$i]++;
                break;
            }
        }
    }

    public function lines(): string
    {
        if ($this->series === []) {
            return '';
        }

        $out = '';
        if ($this->help !== '') {
            $out .= "# HELP {$this->name} {$this->help}\n";
        }
        $out .= "# TYPE {$this->name} histogram\n";

        $leValues = [...$this->buckets, '+Inf'];
        foreach ($this->series as $key => $s) {
            $labels = $this->labelsFromKey($key);
            $suffix = $this->labelPairs($labels);

            // 累计分桶（bucket[i] 含所有 <= 上界的值）。Prometheus 约定名为 name_bucket。
            $cumulative = 0;
            foreach ($this->buckets as $i => $upper) {
                $cumulative += $s['buckets'][$i];
                $out .= $this->name . '_bucket' . $this->wrap($suffix, 'le="' . $upper . '"') . " {$cumulative}\n";
            }
            $out .= $this->name . '_bucket' . $this->wrap($suffix, 'le="+Inf"') . " {$s['count']}\n";
            $out .= $this->name . '_sum' . $this->wrapClose($suffix) . " {$s['sum']}\n";
            $out .= $this->name . '_count' . $this->wrapClose($suffix) . " {$s['count']}\n";
        }

        return $out;
    }

    /**
     * 生成标签对串（不含花括号）：如 `k="v",m="n"`；无标签返回空串。
     *
     * @param array<string, string> $labels
     */
    private function labelPairs(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = $k . '="' . $this->escape((string) $v) . '"';
        }

        return implode(',', $pairs);
    }

    /**
     * 包裹带 le 的标签串：空标签 → `{le="x"}`；有标签 → `{k="v",le="x"}`。
     */
    private function wrap(string $suffix, string $extra): string
    {
        return $suffix === '' ? '{' . $extra . '}' : '{' . $suffix . ',' . $extra . '}';
    }

    /**
     * 包裹普通（无 le）标签串：空标签 → `{}`；有标签 → `{k="v"}`。
     */
    private function wrapClose(string $suffix): string
    {
        return $suffix === '' ? '{}' : '{' . $suffix . '}';
    }

    /**
     * @param array<string, string|int> $labelValues
     */
    private function serialize(array $labelValues): string
    {
        $parts = [];
        foreach ($this->labelKeys as $k) {
            $parts[] = (string) ($labelValues[$k] ?? '');
        }

        return implode("\x1f", $parts);
    }

    /**
     * @return array<string, string>
     */
    private function labelsFromKey(string $key): array
    {
        $values = explode("\x1f", $key);
        $labels = [];
        foreach ($this->labelKeys as $i => $k) {
            $labels[$k] = $values[$i] ?? '';
        }

        return $labels;
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}

/**
 * 绑定了标签值的 histogram 视图。
 */
final class BoundHistogram
{
    /**
     * @param array<string, string|int> $labelValues
     */
    public function __construct(
        private readonly Histogram $histogram,
        private readonly array $labelValues,
    ) {
    }

    public function observe(float $value): void
    {
        $this->histogram->observeBy($this->labelValues, $value);
    }
}
