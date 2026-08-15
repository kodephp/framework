<?php

declare(strict_types=1);
/**
 * hyperf 对标路由（与 kode/webman/raw 同形态：最小响应 + 业务输出）。
 */
use Hyperf\HttpServer\Router\Router;
use Hyperf\HttpServer\Response;

Router::get('/ping', function (Response $response) {
    return $response->json(['pong' => true]);
});

Router::get('/bench/json', function (Response $response) {
    $items = [];
    for ($i = 1; $i <= 50; ++$i) {
        $items[] = ['id' => $i, 'name' => 'item-' . $i];
    }

    return $response->json(['items' => $items]);
});
