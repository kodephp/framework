<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Metrics;

/**
 * 计数器（Prometheus counter 语义）
 *
 * 单调自增。支持标签（labels）：用 {@see with()} 绑定一组标签值后得到一个
 * 「带标签的计数视图」，对其 inc() 即累加到对应时间序列。
 *
 * 设计立场：标签值不参与对象标识，仅参与输出行；同一名称 + 标签键集合只创建一个
 * 顶层 Counter，避免重复注册。标签维度由开发者在 inc 前用 with() 指定。
 */
final class Counter
{
    /**
     * @param string               $name      指标名（符合 Prometheus 命名：字母/数字/下划线）
     * @param string               $help      指标说明（# HELP）
     * @param array<int, string>   $labelKeys 标签键集合（顺序即输出顺序）
     */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly array $labelKeys = [],
    ) {
    }

    /** @var array<string, int|float> 序列化标签值 => 累计值 */
    private array $series = [];

    /**
     * 绑定标签值，返回可 inc 的视图。
     *
     * @param array<string, string|int> $labelValues
     */
    public function with(array $labelValues): BoundCounter
    {
        return new BoundCounter($this, $labelValues);
    }

    /**
     * 无标签便捷累加（等价于 with([])->inc()）。
     */
    public function inc(int|float $by = 1): void
    {
        $this->incBy([], $by);
    }

    /**
     * 直接按标签值累加（内部用，避免每请求 new 视图）。
     *
     * @param array<string, string|int> $labelValues
     */
    public function incBy(array $labelValues, int|float $by = 1): void
    {
        $key = $this->serialize($labelValues);
        $this->series[$key] = ($this->series[$key] ?? 0) + $by;
    }

    /**
     * 生成 Prometheus 文本行（含 TYPE / HELP / 各序列）。
     */
    public function lines(): string
    {
        if ($this->series === []) {
            return '';
        }

        $out = '';
        if ($this->help !== '') {
            $out .= "# HELP {$this->name} {$this->help}\n";
        }
        $out .= "# TYPE {$this->name} counter\n";

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
 * 绑定了标签值的计数视图。
 */
final class BoundCounter
{
    /**
     * @param array<string, string|int> $labelValues
     */
    public function __construct(
        private readonly Counter $counter,
        private readonly array $labelValues,
    ) {
    }

    public function inc(int|float $by = 1): void
    {
        $this->counter->incBy($this->labelValues, $by);
    }
}
