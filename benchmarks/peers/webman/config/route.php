<?php

declare(strict_types=1);

use Webman\Route;

// 仅镜像两条对标路由：/ping（最小响应）与 /bench/json（DI 等价 + 50 条记录 JSON）。
Route::get('/ping', static fn () => json(['pong' => true]));

Route::get('/bench/json', static function () {
    return json([
        'framework' => 'webman',
        'now'       => date('c'),
        'items'     => array_map(
            static fn (int $i) => ['id' => $i, 'name' => "item-$i"],
            range(1, 50)
        ),
    ]);
});
