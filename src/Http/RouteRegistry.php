<?php

declare(strict_types=1);

namespace Kode\Framework\Http;

use Kode\Http\Routing\Route;

/**
 * 路由来源登记表
 *
 * 框架在加载路由文件（app/routes.php、插件 routes.php、config 声明的额外来源）
 * 时，为每条新注册的路由打上「来源标签」，便于 `route:list` 命令按来源/文件
 * 聚合展示，也支持插件（独立模块）路由的可读性。
 *
 * 用 spl_object_id 作键，零侵入（不修改 kode/http 的 Route 对象）。
 */
final class RouteRegistry
{
    /** @var array<int, string> spl_object_id(Route) => 来源标签 */
    private array $sources = [];

    /**
     * 为一条路由标记来源。
     */
    public function tag(Route $route, string $source): void
    {
        $this->sources[spl_object_id($route)] = $source;
    }

    /**
     * 查询某条路由的来源标签（未知则返回 null）。
     */
    public function sourceOf(Route $route): ?string
    {
        return $this->sources[spl_object_id($route)] ?? null;
    }

    /**
     * 全部来源标签（spl_object_id => label）。
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
