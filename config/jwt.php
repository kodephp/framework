<?php

/*
 * JWT 配置（kode/jwt）
 *
 * 与 kode/jwt 配置结构对齐；使用 HS256 时必须提供非空 secret。
 */

return [
    'defaults' => [
        'guard' => 'api',
        'storage' => 'memory',
    ],

    'guards' => [
        'api' => [
            'driver' => 'sso',
            'storage' => 'memory',
            'algo' => env('JWT_ALGO', 'HS256'),
            'secret' => env('JWT_SECRET', 'kode-framework-secret-change-me'),
            'ttl' => (int) env('JWT_TTL', 3600),
            'refresh_ttl' => 604800,
            'blacklist_enabled' => true,
            'blacklist_ttl' => 604800,
            // SSO 守卫要求：签发与校验都必须携带 platform 声明
            'platform' => env('JWT_PLATFORM', 'web'),
            'clock_skew' => 30,
            'expected_claims' => [],
        ],
    ],

    'storage' => [
        'memory' => ['driver' => 'memory'],
    ],
];
