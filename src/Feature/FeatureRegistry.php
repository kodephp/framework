<?php

declare(strict_types=1);

namespace Kode\Framework\Feature;

use Kode\Http\Routing\Route;

/**
 * 路由 → flag 登记表（零侵入，不修改 kode/http 的 Route 对象）。
 *
 * 用 spl_object_id 作键，与 RouteRegistry 的限流登记表同一范式。
 */
final class FeatureRegistry
{
    /** @var array<int, array{flag: string, fallback: int}> spl_object_id(Route) => 条目 */
    private array $flags = [];

    public function tag(Route $route, string $flag, int $fallback = 404): void
    {
        $this->flags[spl_object_id($route)] = ['flag' => $flag, 'fallback' => $fallback];
    }

    /**
     * @return array{flag: string, fallback: int}|null
     */
    public function flagOf(Route $route): ?array
    {
        return $this->flags[spl_object_id($route)] ?? null;
    }

    /**
     * @return array<int, array{flag: string, fallback: int}>
     */
    public function all(): array
    {
        return $this->flags;
    }
}
