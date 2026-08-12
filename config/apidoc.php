<?php

declare(strict_types=1);

/**
 * API 文档自动化配置
 *
 * 框架本地薄实现：扫描已注册路由，生成 OpenAPI 3.0 spec，并提供 Swagger UI 浏览页。
 * 控制器方法可用 #[OpenApi] 属性补充 summary / description / tags / requestBody / responses。
 */
return [
    // 总开关
    'enabled' => true,

    // OpenAPI info
    'title' => env('APP_NAME', 'Kode Framework API'),
    'version' => '1.0.0',
    'description' => '由 Kode Framework 自动生成的 API 文档',
    'contact' => [
        'name' => 'Kode Framework',
    ],

    // 可选：显式服务器地址（留空则由 Swagger UI 按当前 host 补全）
    'servers' => [],

    // 端点路径
    'json_path' => '/docs/openapi.json', // spec JSON
    'ui_path' => '/docs',               // Swagger UI 浏览页

    // Swagger UI 渲染方式：'cdn'（默认，引用 unpkg 静态资源）
    'ui' => 'cdn',

    // 是否对 UI 与 JSON 端点做基础保护：'none' | 'token' | 'local'
    'protect' => 'none',
    'token' => env('API_DOC_TOKEN', ''),

    // 排除的路径前缀（如健康检查、指标），不计入文档
    'ignore_paths' => ['/health', '/metrics', '/ping'],
];
