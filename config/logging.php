<?php

/*
 * 日志配置（Monolog，遵循 PSR-3）
 */

return [
    'name' => env('APP_NAME', 'kode'),
    'path' => base_path('storage/logs/app.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'rotate' => true,
];
