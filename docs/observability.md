# 可观测性（指标 + 链路追踪）

框架本地薄实现：复用 [kode/context](https://github.com/kodephp) 做链路上下文、用 Prometheus 文本格式暴露指标，不引入额外依赖。所有能力通过 `config/observability.php` 开关。

## 一、链路追踪（Trace）

`TraceMiddleware` 是最外层中间件，保证**每个响应**都带链路头：

- 优先读取 W3C `traceparent`（`00-<traceId>-<spanId>-<flags>`），兼容 `X-Trace-Id` / `X-Span-Id`；
- 缺省则自动生成 32 位 `trace_id` 与 16 位 `span_id`；
- 写入 `kode/context` 的 `TRACE_ID` / `SPAN_ID`，并**回写 `$_SERVER['HTTP_X_TRACE_ID']`**，使 kode/exception 的分布式追踪复用同一 traceId（异常响应与正常响应的 traceId 一致）；
- 通过 `TraceContext::childSpan()` 衍生子 span；
- 响应自动附带 `traceparent` + `X-Trace-Id` + `X-Span-Id`；向其它服务发起 HTTP 调用时用 `TraceContext::outgoingHeaders()` 注入实现跨服务串联。

```php
use function Kode\Framework\Support\trace;

$id = trace()::traceId();            // 当前链路 ID（32 位 hex）
$headers = trace()::outgoingHeaders(); // 注入到下游调用
```

## 二、自动指标（Metrics）

`MetricsMiddleware` 自动采集 HTTP 吞吐与时延（跳过 `skip_paths` 中的 `/metrics`、`/health*`、`/ping`）：

- `http_requests_total{method, route, code_class}` —— 计数器；
- `http_request_duration_seconds{method, route, code_class}` —— 直方图（分桶统计 P 分位）。

指标注册表为单例（门面 `Metrics` / 助手 `metrics()`），业务代码也可手动埋点：

```php
use Kode\Framework\Facades\Metrics;

Metrics::counter('orders_created_total', '下单总数', ['channel'])
    ->with(['channel' => 'web'])->inc();

Metrics::histogram('order_amount_sum', '订单金额', [])
    ->observe(199.00);

Metrics::gauge('queue_size', '队列积压', ['name'])
    ->with(['name' => 'mail'])->set(7);
```

## 三、/metrics 端点（Prometheus 抓取）

受保护的指标端点，供 Prometheus 抓取：

```php
// config/observability.php
return [
    'metrics' => [
        'enabled'  => true,
        'path'     => '/metrics',
        'protect'  => 'token',   // token | local | none
        'token'    => env('OBS_METRICS_TOKEN', ''), // 留空则启动时随机生成并打印到 STDERR
        'skip_paths' => ['/metrics', '/health', '/health/ready', '/ping'],
    ],
    'tracing' => ['enabled' => true],
];
```

- `protect=token`：通过 `?token=` 或 `Authorization: Bearer <token>` 校验；
- `protect=local`：仅允许 `127.*` / `::1`；
- `protect=none`：直接开放（不推荐生产）。

抓取示例：

```text
GET /metrics?token=xxxx
# TYPE http_requests_total counter
http_requests_total{method="GET",route="/users/{id}",code_class="2xx"} 12
# TYPE http_request_duration_seconds histogram
...
```

## 四、测试要点

`TraceMiddleware` 为最外层，故测试中任意响应均带 `X-Trace-Id` / `traceparent`；`/metrics` 受保护，未带令牌返回 `403`。
