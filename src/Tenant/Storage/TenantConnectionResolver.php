<?php

declare(strict_types=1);

namespace Kode\Framework\Tenant\Storage;

/**
 * 租户连接解析器契约（薄壳层核心抽象）。
 *
 * 给定租户标识，返回 kode/database 的「连接配置数组」；返回 null 表示「不隔离 / 仍用默认连接」。
 * 框架内置 {@see StaticTenantStorageResolver}（shared/database/schema/map 四种静态策略）；
 * 真实 SaaS 常用「从中心租户表动态查连接凭证」——实现本接口并注入容器即可零改动接入，
 * TenantStorageManager 的切换 / 恢复 / 事件机制完全复用。
 *
 * 这是薄壳原则的体现：框架只定义「租户 → 连接配置」的契约与内置静态实现，
 * 不绑定任何具体的租户元数据存储（中心库 / 配置中心 / 启动期文件）。
 */
interface TenantConnectionResolver
{
    /**
     * 解析某租户的连接配置。
     *
     * @return array<string, mixed>|null kode/database 连接配置数组；
     *                                   返回 null 表示不隔离（使用默认连接）。
     */
    public function resolve(string $tenantId): ?array;
}
