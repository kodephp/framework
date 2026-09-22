<?php

declare(strict_types=1);

namespace Kode\Framework\Observability\Trace;

use Kode\Context\Context;
use Kode\Framework\Observability\Trace\Contracts\SpanExporter;
use Psr\Log\LoggerInterface;

/**
 * 分布式追踪管理器（薄壳层核心）。
 *
 * 职责：
 *  - 录制 span：{@see start()} 基于当前链路（kode/context 的 trace_id/span_id）开子跨度，
 *    结束 {@see end()} 回填时长与状态，进入待导出缓冲。
 *  - 采样：根 span 按 sample_ratio 决策，子 span 继承父采样，保证一条链路一致。
 *  - 隔离：active 栈与待导出缓冲存于 kode/context（按执行单元隔离），并发 fiber / 进程 /
 *    线程各持独立链路，天然支持 kode/fibers 的 active runtime。
 *  - 导出：根 span 结束且配置 flush_on_request_end 时自动 flush；或手动 {@see flush()}。
 *    导出器（SpanExporter）由容器注入，失败仅告警、不阻断请求。
 *
 * 设计立场：框架只做「录制 + 标准 OTLP 导出钩子」，不内置 OTel SDK；真实后端
 * （gRPC、第三方 APM）实现 SpanExporter 注入即零改动接入——与配置中心、服务发现同哲学。
 */
final class Tracer
{
    private const string CTX_STACK = 'kode.tracing.stack';

    private const string CTX_BUFFER = 'kode.tracing.buffer';

    /**
     * 进程级待导出队列（离请求路径批量发送）。
     *
     * 按进程隔离（Swoole / Workerman 每个 worker 独立），请求结束仅做内存入队，
     * 真实网络发送由 {@see drain()} 在定时器 / 停机钩子中执行，绝不阻塞客户端响应。
     *
     * @var array<int, Span>
     */
    private static array $outbox = [];

    /**
     * outbox 逻辑头指针：超出容量上限（max_outbox）后不再 array_slice 物理裁剪，
     * 而是步进 head 语义丢弃最旧 span（零复制）。drain / resetOutbox 时一并归零，
     * 保证物理数组不会长期膨胀（峰值 ≤ 2×cap + 1 个元素）。
     */
    private static int $outboxHead = 0;

    private ?SpanExporter $exporter = null;

    /**
     * 导出失败退避状态（进程级，与 {@see self::$outbox} 同生命周期）。
     *
     * 必须是静态：FPM / 每请求重建容器的场景下，实例级状态会随请求一起消失，
     * 退避形同虚设（每请求现场再付一次 curl 超时 + 一条告警）。
     */
    private static int $exportFailures = 0;

    private static int $suppressedFailures = 0;

    /** 累计真正写出的失败告警条数（退避策略压掉了多少条，看这个）。 */
    private static int $exportWarnings = 0;

    private static int $backoffWindow = 0;

    private static float $retryAfter = 0.0;

    /**
     * 是否异步导出：true = 请求路径仅内存入队，export 离请求路径执行。
     * @var bool
     */
    private readonly bool $async;

    /**
     * @param array<string, mixed> $config     observability.tracing 配置
     * @param \Closure|null        $dispatcher 事件派发器（注入，解耦事件系统启动顺序）
     * @param \Closure|null        $exporterResolver 解析 SpanExporter（延迟，避免未启用时构建）
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $config = [],
        private readonly ?\Closure $dispatcher = null,
        private readonly ?\Closure $exporterResolver = null,
        ?bool $async = null,
    ) {
        // async 默认开（请求路径仅内存入队，离请求路径导出）；构造显式传 false 或
        // config['async']=false 时退化为请求结束同步导出（兼容测试与需即时落盘场景）。
        $this->async = $async ?? (bool) ($this->config['async'] ?? true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * 开启一个 span（自动作为当前 active span 的子跨度）。
     *
     * @param array<string, mixed> $attributes
     * @param bool|null            $sampled 采样决策（根跨度由调用方在 {@see decideSampled()} 先行判定后传入，
     *                                     避免「已决定不采样却仍创建 span 对象」的无效开销）。
     */
    public function start(string $name, array $attributes = [], int $kind = SpanKind::INTERNAL, ?bool $sampled = null): Span
    {
        if (!$this->enabled) {
            return new Span('', '', null, $name, $kind, microtime(true), $attributes, false, true);
        }

        $active = $this->active();
        $traceId = $this->ensureTraceId();

        if ($active !== null) {
            // 子跨度：推进当前 span，parent 指向 active
            TraceContext::childSpan();
            $spanId = TraceContext::spanId() ?? TraceContext::newSpanId();
            $parentSpanId = $active->spanId;
            $sampled = $sampled ?? $active->sampled;
        } else {
            // 根跨度：复用请求级 span_id（与响应 traceparent 一致），采样按比率决策
            $spanId = TraceContext::spanId() ?? TraceContext::newSpanId();
            $parentSpanId = TraceContext::parentSpanId();
            $sampled = $sampled ?? $this->decideSampled();
        }

        $span = new Span(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            name: $name,
            kind: $kind,
            start: microtime(true),
            attributes: $attributes,
            sampled: $sampled,
        );

        $this->pushStack($span);

        return $span;
    }

    /**
     * 便捷包裹：开 span → 执行 → 结束（异常时记错误并上抛）。
     *
     * @template T
     * @param callable(Span): T $work
     * @return T
     */
    public function span(string $name, array $attributes, int $kind, callable $work): mixed
    {
        $span = $this->start($name, $attributes, $kind);
        try {
            return $work($span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $this->end($span, SpanStatus::ERROR, $e->getMessage());
            throw $e;
        }
    }

    /**
     * 结束 span（回填时长/状态）、入缓冲，根 span 结束时按需 flush。
     */
    public function end(Span $span, int $statusCode = SpanStatus::OK, string $statusMessage = ''): void
    {
        if ($span->noop || !$span->sampled) {
            $this->popStack($span);

            return;
        }

        if ($span->end === null) {
            $span->finish(microtime(true), $statusCode, $statusMessage);
        }

        $this->popStack($span);
        $this->appendBuffer($span);

        if ($this->stack() === [] && !empty($this->config['flush_on_request_end'])) {
            // 请求结束：优先离请求路径导出（异步入队），避免阻塞客户端响应。
            $this->enqueueFlush();
        }
    }

    /**
     * 记录异常到指定 span（不抛，可继续处理）。
     */
    public function recordException(Span $span, \Throwable $e, array $attributes = []): void
    {
        $span->recordException($e, $attributes);
    }

    /**
     * 当前 active（栈顶）span。
     */
    public function active(): ?Span
    {
        $stack = $this->stack();

        return $stack === [] ? null : end($stack);
    }

    /**
     * 请求结束时的导出钩子：默认异步（仅把 span 放入进程级待导出队列，µs 级、不阻塞响应），
     * 真实网络发送由定时器 / 优雅停机钩子 {@see drain()} 离请求路径执行——与 OTel
     * BatchSpanProcessor 同范式，避免每请求一次同步阻塞的 OTLP POST 拖垮吞吐。
     *
     * 关闭 async 时退化为同步 flush（兼容测试与需「请求结束即落盘」的场景）。
     *
     * @return int 本次入队 / 导出的 span 数
     */
    public function enqueueFlush(): int
    {
        if (!$this->enabled) {
            return 0;
        }

        $buffer = $this->buffer();
        if ($buffer === []) {
            return 0;
        }

        if (!$this->async) {
            // 同步模式：请求结束即导出，但仍受退避保护——否则 Collector 不可达时
            // 每个请求都要在现场付满一次 curl 超时（默认 2s），吞吐直接塌方。
            return $this->drain();
        }

        // 异步：当前执行单元的 span 合并进进程级 outbox，清空本单元缓冲。
        // 逐元素 append（PHP 数组尾部追加摊销 O(1)），替代 array_merge 的每请求
        // O(N) 全量复制；cap 裁剪改走 outboxHead 头指针「逻辑丢弃」（零复制），
        // 彻底消除压测/高流量且无 drain 定时器时 outbox 增长引发的数组乒乓
        // （outbox 4095 时旧实现单次 end 高达 ~20µs，是 L4/L5 梯度断层的主因）。
        $this->pushToOutbox($buffer);
        Context::set(self::CTX_BUFFER, []);

        return count($buffer);
    }

    /**
     * 把 span 追加进进程级 outbox，并按 max_outbox 丢最旧。
     *
     * 入队（{@see enqueueFlush()}）与导出失败回灌（{@see drain()}）共用，
     * 保证两条路径的裁剪语义完全一致。
     *
     * @param array<int, Span> $spans
     */
    private function pushToOutbox(array $spans): void
    {
        foreach ($spans as $span) {
            self::$outbox[] = $span;
        }
        $cap = $this->maxOutbox();
        if (count(self::$outbox) - self::$outboxHead > $cap) {
            self::$outboxHead++;
        }
        // 物理裁剪：头指针越过 2×cap 时把已逻辑丢弃的最旧段一次清掉。
        // drain（定时器/停机钩子）不存在时 outbox 数组仍会随请求物理增长，
        // 长跑（native/CLI 常驻、长时间压测）会演变为无界内存泄漏；
        // 每 2×cap 次入队触发一次 O(2×cap) 裁剪，摊薄后仍为 O(1)，内存上限封顶。
        if (self::$outboxHead >= 2 * $cap) {
            self::$outbox = array_slice(self::$outbox, self::$outboxHead);
            self::$outboxHead = 0;
        }
    }

    /**
     * 离请求路径导出：把进程级 outbox（及当前缓冲）批量发送到导出器。
     *
     * 由以下时机调用（均不阻塞客户端响应）：
     *  - 常驻 worker 的周期性 tick 定时器（见 flush_interval_ms）；
     *  - FPM / CLI 的 register_shutdown_function（响应已发出之后）；
     *  - worker 优雅停机钩子（GracefulShutdown，force=true 作最后一次性尝试）。
     *
     * 失败退避：导出器不可达时（Collector 挂了 / 端点配错），每次尝试都要付满
     * curl 超时（默认 2s）。无节流时 tick 每 2s 一次 = 每个 worker 常年每秒阻塞近 1s，
     * 且同一异常刷屏日志。故失败后按 export_backoff_ms 指数退避（翻倍至
     * export_backoff_max_ms 封顶），退避窗口内直接返回 0（不碰网络、不打日志），
     * span 留在 outbox 等恢复；日志只在首次失败与每 10 次失败时打一条。
     *
     * @param bool $force 忽略退避窗口立即尝试（停机前的最后机会、手动命令）
     *
     * @return int 成功导出的 span 数（0 = 无数据 / 失败 / 处于退避窗口）
     */
    public function drain(bool $force = false): int
    {
        if (!$this->enabled) {
            return 0;
        }

        if (!$force && microtime(true) < self::$retryAfter) {
            return 0;
        }

        $pending = array_merge(
            $this->buffer(),
            self::$outboxHead > 0 ? array_slice(self::$outbox, self::$outboxHead) : self::$outbox,
        );
        if ($pending === []) {
            return 0;
        }

        $exporter = $this->exporter();
        if ($exporter === null) {
            self::$outbox = [];
            self::$outboxHead = 0;
            Context::set(self::CTX_BUFFER, []);

            return 0;
        }

        $name = $exporter->name();
        $count = count($pending);
        try {
            $exporter->export($pending);
            self::$outbox = [];
            self::$outboxHead = 0;
            Context::set(self::CTX_BUFFER, []);
            $this->noteExportSuccess($name, $count);
            $this->dispatch(new SpansFlushed($count, $name, true));

            return $count;
        } catch (\Throwable $e) {
            // 导出失败：本批 span 回灌 outbox 等下次 drain 重试（清空执行单元缓冲即可，
            // 直接丢弃会让同步路径 / FPM 下每次失败都静默丢掉整条链路），仅告警、不阻断业务。
            $this->pushToOutbox($pending);
            Context::set(self::CTX_BUFFER, []);
            $suppressed = $this->noteExportFailure();
            if ($suppressed !== null) {
                try {
                    logger()->warning('span 导出失败，已保留待重试', [
                        'exporter' => $name,
                        'count' => $count,
                        'failures' => self::$exportFailures,
                        // 本次告警之前被退避策略压掉的失败次数（防刷屏，不代表没重试）
                        'suppressed' => $suppressed,
                        'retry_after_ms' => self::$backoffWindow,
                        'exception' => $e,
                    ]);
                } catch (\Throwable) {
                    // logger 不可用时忽略
                }
            }
            $this->dispatch(new SpansFlushed($count, $name, false, $e->getMessage()));

            return 0;
        }
    }

    /**
     * 记录一次失败并推进退避窗口。
     *
     * @return int|null 应打日志时返回「本次告警前被压掉的失败次数」，应静默时返回 null
     */
    private function noteExportFailure(): ?int
    {
        ++self::$exportFailures;
        $base = max(0, (int) ($this->config['export_backoff_ms'] ?? 1000));
        $max = max($base, (int) ($this->config['export_backoff_max_ms'] ?? 60000));
        // 指数退避：base × 2^(n-1)，封顶 max（1s→2s→4s…→60s 后每分钟最多再试一次）。
        self::$backoffWindow = $base === 0
            ? 0
            : min($max, (int) ($base * (2 ** min(16, self::$exportFailures - 1))));
        self::$retryAfter = self::$backoffWindow === 0
            ? 0.0
            : microtime(true) + (self::$backoffWindow / 1000);

        // 只在第 1、11、21… 次失败时打日志：Collector 长时间不可达时，
        // 「60s 窗口 × N worker」也能刷出上万条同一条告警，运维真正要看的是
        // 「仍在失败 + 已失败多久 + 压掉了多少条」，故其余次数只计数。
        if (self::$exportFailures > 1 && (self::$exportFailures - 1) % 10 !== 0) {
            ++self::$suppressedFailures;

            return null;
        }

        $suppressed = self::$suppressedFailures;
        self::$suppressedFailures = 0;
        ++self::$exportWarnings;

        return $suppressed;
    }

    /** 导出恢复：清零退避状态，曾在失败时才提示一次。 */
    private function noteExportSuccess(string $exporter, int $count): void
    {
        if (self::$exportFailures === 0) {
            return;
        }

        $failures = self::$exportFailures;
        self::$exportFailures = 0;
        self::$suppressedFailures = 0;
        self::$backoffWindow = 0;
        self::$retryAfter = 0.0;

        try {
            logger()->info('span 导出已恢复', [
                'exporter' => $exporter,
                'count' => $count,
                'failed_attempts' => $failures,
            ]);
        } catch (\Throwable) {
            // logger 不可用时忽略
        }
    }

    /**
     * 连续导出失败次数（0 = 无失败）。用于健康探针与单测断言退避是否生效。
     */
    public function exportFailures(): int
    {
        return self::$exportFailures;
    }

    /**
     * 累计写出的「导出失败」告警条数（远小于 {@see exportFailures()} 即说明退避在压刷屏）。
     */
    public function exportWarnings(): int
    {
        return self::$exportWarnings;
    }

    /**
     * 剩余退避时间（毫秒）；0 表示下一次 drain 会真正尝试导出。
     */
    public function exportRetryInMs(): int
    {
        $left = self::$retryAfter - microtime(true);
        if ($left <= 0) {
            return 0;
        }
        // 先 round 再 ceil：retryAfter 是 microtime + 窗口秒数，浮点累加会带 1e-9 级的
        // 零头，直接 ceil 会把 400ms 窗口报成 401ms（断言/探针都嫌脏）。
        return (int) ceil(round($left * 1000, 3));
    }

    /**
     * 清空退避状态（测试隔离 / 手动强制重试前调用）。与退避状态同为进程级。
     */
    public static function resetExportBackoff(): void
    {
        self::$exportFailures = 0;
        self::$suppressedFailures = 0;
        self::$exportWarnings = 0;
        self::$backoffWindow = 0;
        self::$retryAfter = 0.0;
    }

    /**
     * 同步导出（兼容测试、显式调用）。
     * 等价于 {@see drain(force: true)}：显式要求导出时不吃退避窗口的亏，
     * 便于单测断言导出行为。请求结束路径上的自动导出走 enqueueFlush()，
     * 那条路才受退避保护。
     *
     * @return int 成功导出的 span 数（0 = 无数据或失败）
     */
    public function flush(): int
    {
        return $this->drain(true);
    }

    /**
     * 当前缓冲中的 span 数。
     */
    public function buffered(): int
    {
        return count($this->buffer());
    }

    /**
     * 待导出总数（当前执行单元缓冲 + 进程级 outbox）。
     *
     * 运维口径：Collector 恢复前该数只会涨；涨到 max_outbox 即开始丢最旧的链路。
     */
    public function pendingCount(): int
    {
        return count($this->buffer()) + max(0, count(self::$outbox) - self::$outboxHead);
    }

    /**
     * 当前导出器名称（未解析/未启用时返回 null）。
     */
    public function exporterName(): ?string
    {
        return $this->exporter()?->name();
    }

    /**
     * 清空进程级待导出队列（主要用于测试隔离，避免异步 outbox 跨用例串扰）。
     */
    public static function resetOutbox(): void
    {
        self::$outbox = [];
        self::$outboxHead = 0;
        self::resetExportBackoff();
    }

    // ------------------------------------------------------------------
    // 内部：执行单元隔离的栈 / 缓冲（kode/context）
    // ------------------------------------------------------------------

    /** @return array<int, Span> */
    private function stack(): array
    {
        return Context::get(self::CTX_STACK, []);
    }

    private function pushStack(Span $span): void
    {
        $stack = $this->stack();
        $stack[] = $span;
        Context::set(self::CTX_STACK, $stack);
    }

    private function popStack(Span $span): void
    {
        $stack = $this->stack();

        // 快路径：叶子 span 结束恒在栈顶（end 的调用语义），直接 array_pop O(1) 零分配；
        // 仅当中段 span 乱序结束（异常/嵌套跳过）才退回 O(n) 过滤兜底。
        if ($stack !== [] && $stack[array_key_last($stack)] === $span) {
            array_pop($stack);
        } else {
            $stack = array_values(array_filter($stack, static fn (Span $s): bool => $s !== $span));
        }

        Context::set(self::CTX_STACK, $stack);
    }

    /** @return array<int, Span> */
    private function buffer(): array
    {
        return Context::get(self::CTX_BUFFER, []);
    }

    private function appendBuffer(Span $span): void
    {
        $buf = $this->buffer();
        $buf[] = $span;
        $max = (int) ($this->config['max_batch'] ?? 512);
        if (count($buf) > $max) {
            array_shift($buf);
        }
        Context::set(self::CTX_BUFFER, $buf);
    }

    private function ensureTraceId(): string
    {
        $traceId = TraceContext::traceId();
        if ($traceId !== null) {
            return $traceId;
        }

        $traceId = TraceContext::newTraceId();
        Context::set(Context::TRACE_ID, $traceId);

        return $traceId;
    }

    /**
     * 根跨度采样决策（head-based）。
     *
     * 调用方（TraceMiddleware）应在创建根 span 前先行调用，未采样则直接跳过 span 创建，
     * 避免「已决定不采样却仍付出上下文 / 对象开销」——这是把 sample_ratio 真正落地为
     * 吞吐优化的关键（否则 start() 总会创建 span 对象，降采样无任何收益）。
     */
    public function decideSampled(): bool
    {
        $ratio = (float) ($this->config['sample_ratio'] ?? 1.0);
        if ($ratio >= 1.0) {
            return true;
        }
        if ($ratio <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() <= $ratio;
    }

    /**
     * outbox 容量上限（超出丢弃最旧），防内存膨胀 / collector 长期不可用时堆积。
     */
    private function maxOutbox(): int
    {
        return (int) ($this->config['max_outbox'] ?? 4096);
    }

    private function exporter(): ?SpanExporter
    {
        if ($this->exporter !== null) {
            return $this->exporter;
        }

        if ($this->exporterResolver !== null) {
            $resolved = ($this->exporterResolver)();
            if ($resolved instanceof SpanExporter) {
                $this->exporter = $resolved;

                return $this->exporter;
            }
        }

        return null;
    }

    private function dispatch(object $event): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($event);
        }
    }
}
