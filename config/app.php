<?php

/*
 * 应用基础配置
 */

return [
    'name' => env('APP_NAME', 'kode-app'),
    'debug' => (bool) env('APP_DEBUG', true),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'env' => env('APP_ENV', 'local'),

    /*
     * 启动期必填配置（fail-fast）。
     *
     * 应用启动时逐一校验下列点号路径配置是否存在且非空；任一缺失即抛出
     * RuntimeException，使「缺配置」在启动即失败（而非运行到某请求才 500）。
     * 默认仅校验最基础两项（它们都有 env() 兜底，通常不会触发）；业务可追加，例如：
     *   'required' => ['app.name', 'app.env', 'services.stripe.key', 'mail.host'],
     */
    'required' => [
        'app.name',
        'app.env',
    ],

    // 额外 ServiceProvider 类（框架内置 Provider 已默认注册，这里仅用于扩展）
    'providers' => [],

    /*
     * 运行时环境（向下桥接 kode/runtime 的统一运行时）。
     *
     * 注意两种“模式”的语义，不要混用：
     *   A. kode/runtime 原生环境（enable 会切换整个活动运行时）：
     *        cli / fiber / process / thread / swoole / swow
     *      - fiber  ：单进程协程调度（kode/fibers），NTS/ZTS 均可用，可回传任务结果 → 作为默认。
     *      - swoole/swow：生产常驻内存运行时（需对应扩展），同样可回传结果。
     *      - process：多进程（kode/process，需 ext-pcntl/posix/sockets）。
     *                 一旦设为活动环境，任务在子进程执行、返回值不回传父进程（仅副作用型任务）。
     *                 因此不要把它作为 Web 请求的默认环境；按需用 runtime()->fork($cb) 跑隔离任务。
     *   B. 自动桥接能力（仅安装对应包后可用，不切换活动环境，supported() 自动识别）：
     *      - parallel    ：kode/parallel（ZTS + ext-parallel 真多线程；非 ZTS 走同步回退）。
     *      - distributed ：kode/fibers + kode/context 分布式桥接。
     *
     * 结论：Web 框架默认用 fiber 作为活动运行时；kode/process、kode/parallel
     * 均已 require 进 composer.json，supported() 会显示可用，按需 enable/fork 即可。
     * ZTS 环境若想让 parallel 成为主并发手段，可把活动环境改为 swoole/swow 后再 enable('parallel')。
     */
    'runtime' => ['fiber'],
];
