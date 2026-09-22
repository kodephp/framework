<?php

declare(strict_types=1);

/**
 * API 文档自动化配置
 *
 * 框架本地薄实现：扫描已注册路由，生成 OpenAPI 3.0 spec，并提供 Swagger UI 浏览页。
 * 控制器方法可用 #[OpenApi] 属性补充 summary / description / tags / requestBody / responses。
 */
return [
    // 总开关（默认关闭：不挂载 /docs 与 /docs/openapi.json 端点）。
    // 需要在线浏览 API 文档时置为 true；离线生成请走 `bin/kode apidoc:generate`。
    'enabled' => false,

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

    // 是否对 UI 与 JSON 端点做基础保护：'none' | 'token' | 'local'
    'protect' => 'none',
    'token' => env('API_DOC_TOKEN', ''),

    // 忽略的路径前缀（如探针 / 指标端点），整段匹配：屏蔽 /metrics 与 /metrics/x，不伤 /metricstore。
    // 端点本身照常服务，只是不进文档（框架 >= 1.7.4）。
    'ignore_paths' => ['/health', '/metrics', '/ping'],

    // apidoc:generate 命令默认写出路径（相对项目根；可用 --output 覆盖）
    'output' => 'storage/apidoc/openapi.json',
];
