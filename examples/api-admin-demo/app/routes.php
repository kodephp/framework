<?php

declare(strict_types=1);

use Kode\Framework\Http\Resp;
use Kode\Http\App;

/*
 * 闭包路由：适合极简接口 / 健康检查 / WebHook。
 * 复杂业务建议用「注解路由 + 控制器」（见 app/http/controllers）。
 */

App::get('/health', static fn () => Resp::json([
    'status'  => 'ok',
    'service' => 'kode-api-admin-demo',
    'time'    => date('c'),
]));

App::get('/ping', static fn () => Resp::json(['message' => 'pong']));
