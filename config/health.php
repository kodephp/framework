<?php

/*
 * 健康检查配置（就绪探针）
 *
 * /health/ready 会对「启用」的探针做连通性检查：任一 error 即 503（流量摘除）；
 * not_configured 不计入失败（未使用的组件不会误报）。
 *
 * 内置探针：db / cache / queue（布尔开关）。
 * 自定义探针：以闭包形式追加（签名 fn(ContainerInterface $c) => 'ok' | 'error: ...'）。
 *
 *   'redis' => function ($c) {
 *       $r = $c->get(\Kode\Cache\CacheManager::class)->connection('redis');
 *       return $r->ping() ? 'ok' : 'error: no pong';
 *   },
 */

return [
    'checks' => [
        'db' => (bool) env('HEALTH_CHECK_DB', true),
        'cache' => (bool) env('HEALTH_CHECK_CACHE', false),
        'queue' => (bool) env('HEALTH_CHECK_QUEUE', false),
    ],
];
