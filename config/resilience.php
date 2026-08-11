<?php

/*
 * 熔断器配置（框架中性 InMemoryBreaker，运行时无关）
 *
 * failure_threshold ：连续失败达到该次数后熔断（进入 open）。
 * recovery_timeout  ：熔断后多久进入半开探活（秒）。
 * half_open_max_calls：半开状态允许的试探请求数。
 *
 * 熔断器为进程内状态，多 worker 各自独立；按服务名隔离（如 'user-service'）。
 */

return [
    'enabled' => true,
    'failure_threshold' => (int) env('BREAKER_FAILURE_THRESHOLD', 5),
    'recovery_timeout' => (float) env('BREAKER_RECOVERY_TIMEOUT', 30.0),
    'half_open_max_calls' => (int) env('BREAKER_HALF_OPEN_CALLS', 1),
];
