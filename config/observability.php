<?php

/*
 * 可观测性配置（指标 + 链路追踪）
 *
 *  - metrics：Prometheus 抓取端点 /metrics（标准 exposition 文本格式）。
 *    生产务必开启保护（protect=token/local），避免指标外泄。
 *  - tracing：分布式链路（trace_id/span_id），基于 kode/context，兼容 W3C traceparent。
 *    同时回写 $_SERVER[HTTP_X_TRACE_ID]，使异常响应的 trace_id 与正常响应一致。
 */

return [
    // ------------------------------------------------------------------
    // 指标（Prometheus）
    // ------------------------------------------------------------------
    'metrics' => [
        // 是否启用 /metrics 端点与自动请求指标采集
        'enabled' => (bool) env('OBS_METRICS_ENABLED', true),

        // 端点路径
        'path' => env('OBS_METRICS_PATH', '/metrics'),

        /*
         * 端点保护策略（指标含 QPS / 错误率等敏感运营数据，生产勿公开）：
         *  - 'token'：需携带 ?token=<TOKEN> 或 Authorization: Bearer <TOKEN>
         *  - 'local'：仅允许回环地址（127.0.0.0/8、::1）访问
         *  - 'none' ：完全公开（仅建议内网/调试）
         */
        'protect' => env('OBS_METRICS_PROTECT', 'token'),

        // 访问令牌；为空时框架启动时生成随机令牌并打印到 stderr（便于一次性使用）。
        'token' => env('OBS_METRICS_TOKEN', ''),

        // 自动请求指标跳过的路径（基础设施端点，避免污染业务指标）
        'skip_paths' => [
            '/metrics', '/health', '/health/live', '/health/ready', '/ping',
        ],
    ],

    // ------------------------------------------------------------------
    // 链路追踪
    // ------------------------------------------------------------------
    'tracing' => [
        'enabled' => (bool) env('OBS_TRACING_ENABLED', true),
    ],
];
