<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Metrics;

/**
 * 仪表盘（Prometheus gauge 语义）
 *
 * 可增可减、可设值，表达「当前瞬时量」：如活跃连接数、队列积压、内存占用、
 * 熔断半开数等。标签用法同 {@see Counter}。
 */
final class Gauge
{
    /**
     * @param string             $name
     * @param string             $help
     * @param array<int, string> $labelKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly array $labelKeys = [],
    ) {
    }

    /** @var array<string, int|float> */
    private array $series = [];

    /**
     * @param array<string, string|int> $labelValues
     */
    public function with(array $labelValues): BoundGauge
    {
        return new BoundGauge($this, $labelValues);
    }

    /**
     * 无标签便捷设值（等价于 with([])->set()）。
     */
    public function set(int|float $value): void
    {
        $this->setBy([], $value);
    }

    /**
     * @param array<string, string|int> $labelValues
     */
    public function setBy(array $labelValues, int|float $value): void
    {
        $this->series[$this->serialize($labelValues)] = $value;
    }

    /**
     * @param array<string, string|int> $labelValues
     */
    public function incBy(array $labelValues, int|float $by = 1): void
    {
        $key = $this->serialize($labelValues);
        $this->series[$key] = ($this->series[$key] ?? 0) + $by;
    }

    /**
     * @param array<string, string|int> $labelValues
     */
    public function decBy(array $labelValues, int|float $by = 1): void
    {
        $this->incBy($labelValues, -$by);
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
        $out .= "# TYPE {$this->name} gauge\n";

        foreach ($this->series as $key => $value) {
            $labels = $this->labelsFromKey($key);
            $out .= $this->format($labels) . " {$value}\n";
        }

        return $out;
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

    /**
     * @param array<string, string> $labels
     */
    private function format(array $labels): string
    {
        if ($labels === []) {
            return $this->name;
        }

        $pairs = [];
        foreach ($labels as $k => $v) {
            $pairs[] = $k . '="' . $this->escape((string) $v) . '"';
        }

        return $this->name . '{' . implode(',', $pairs) . '}';
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}

/**
 * 绑定了标签值的 gauge 视图。
 */
final class BoundGauge
{
    /**
     * @param array<string, string|int> $labelValues
     */
    public function __construct(
        private readonly Gauge $gauge,
        private readonly array $labelValues,
    ) {
    }

    public function set(int|float $value): void
    {
        $this->gauge->setBy($this->labelValues, $value);
    }

    public function inc(int|float $by = 1): void
    {
        $this->gauge->incBy($this->labelValues, $by);
    }

    public function dec(int|float $by = 1): void
    {
        $this->gauge->decBy($this->labelValues, $by);
    }
}
