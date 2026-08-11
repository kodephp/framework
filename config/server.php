<?php

/*
 * HTTP 多进程服务配置（kode/process master-worker 运行时）
 *
 * 由 `bin/kode serve` 读取；CLI 参数（--host/--port/--workers）优先级高于本文件。
 * host/port 决定监听地址；workers 为 worker 进程数（默认取 CPU 核心数）；
 * max_request 为单 worker 累计处理多少请求后自动回收（防内存泄漏，0=不回收）；
 * reuse_port 在多 Worker 高并发场景下可提升端口绑定吞吐（依赖 OS 支持）。
 */

return [
    'host'        => env('SERVER_HOST', '127.0.0.1'),
    'port'        => (int) env('SERVER_PORT', 9527),
    'workers'     => (int) env('SERVER_WORKERS', 0), // 0 = 自动取 CPU 核心数
    'max_request' => (int) env('SERVER_MAX_REQUEST', 0),
    'reuse_port'  => (bool) env('SERVER_REUSE_PORT', false),
    'name'        => env('SERVER_NAME', 'kode-http'),
];
