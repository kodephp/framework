<?php

declare(strict_types=1);

/**
 * 多租户配置（kode/context 薄壳委托 —— 仅提供「租户上下文原语」，不绑定存储策略）。
 *
 * 框架只负责：在请求入口解析租户 → 写入请求级 Context → 暴露 tenant() 助手。
 * 「租户对应哪个库 / schema / 记录」由应用层自行实现（不在框架范围）。
 *
 * resolver 支持三种形态：
 *  - 'header'    从请求头解析（默认 X-Tenant-Id）
 *  - 'subdomain' 从子域名第一段解析（适合每租户独立子域的 SaaS）
 *  - <FQCN>      应用自定义的 Kode\Framework\Tenant\TenantResolver 实现
 */
return [
    // 是否启用租户中间件（关闭则 tenant() 恒返回 null）。
    'enabled' => env('TENANT_ENABLED', false),

    // 解析策略：'header' | 'subdomain' | 自定义 TenantResolver 类名；null = 不解析（仅用 default）。
    'resolver' => env('TENANT_RESOLVER', null),

    // 解析失败 / 无解析器时的回退租户（null = 无默认，tenant() 返回 null）。
    'default' => env('TENANT_DEFAULT', null),

    // 各内置解析器参数。
    'header' => [
        'name' => env('TENANT_HEADER', 'X-Tenant-Id'),
    ],
    'subdomain' => [
        'base_domain' => env('TENANT_BASE_DOMAIN', ''), // 形如 'example.com'，为空则不剔除
    ],
];
