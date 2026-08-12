<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 列出全部路由：按分组聚合并显示数量，支持来源/文件维度，
 * 大项目或插件化开发也能一眼看清每条路由的归属。
 *
 * 用法：
 *   bin/kode console route:list                 # 全量，按分组展示
 *   bin/kode console route:list --compact       # 仅看「分组 → 数量」摘要
 *   bin/kode console route:list --group=api     # 只看某分组
 *   bin/kode console route:list --method=POST   # 按 HTTP 方法过滤
 *   bin/kode console route:list --source=app    # 按来源标签过滤（app / plugin:blog）
 *   bin/kode console route:list --rate-limit    # 额外显示每条路由的 #[RateLimit] 规则
 *   bin/kode console route:list --columns=method,uri,name  # 自定义显示的列
 */
#[AsCommand(
    name: 'route:list',
    description: '列出全部路由（按分组/来源聚合，支持过滤与字段选择）',
    usage: 'route:list [--compact] [--group=NAME] [--method=METHOD] [--source=LABEL] [--rate-limit] [--columns=method,uri,name]',
)]
final class RouteListCommand extends Command
{
    /** 可用列及其取值回调。 */
    private const COLUMNS = [
        'method'     => '方法',
        'uri'        => 'URI',
        'name'       => 'Name',
        'middleware' => 'Middleware',
        'source'     => 'Source',
        'action'     => 'Action',
        'ratelimit'  => 'RateLimit',
    ];

    protected function handle(): int
    {
        /** @var App $app */
        $app = resolve(App::class);
        /** @var RouteRegistry $registry */
        $registry = resolve(RouteRegistry::class);

        $routes = $app->getRouter()->getRoutes();

        // ---- 过滤 ----
        $byGroup = (string) $this->opt('group', '');
        $byMethod = strtoupper((string) $this->opt('method', ''));
        $bySource = (string) $this->opt('source', '');
        $compact = $this->flag('compact', false);
        $rateLimit = $this->flag('rate-limit', false);

        // 列选择：--columns=method,uri 仅显示指定列；否则默认列（--rate-limit 时追加 RateLimit 列）。
        $columns = $this->resolveColumns((string) $this->opt('columns', ''), $rateLimit);

        $rows = [];
        foreach ($routes as $route) {
            $group = $this->groupKey($route);
            if ($byGroup !== '' && $group !== $byGroup) {
                continue;
            }
            if ($byMethod !== '' && !in_array($byMethod, $route->getMethods(), true)) {
                continue;
            }
            $source = $registry->sourceOf($route) ?? 'app';
            if ($bySource !== '' && $source !== $bySource) {
                continue;
            }
            $rows[] = [$route, $group, $source];
        }

        // ---- 按分组聚合 ----
        $groups = [];
        foreach ($rows as [$route, $group, $source]) {
            $groups[$group][] = [$route, $source];
        }
        ksort($groups);

        if ($compact) {
            $this->line('总路由数：' . count($rows) . '，分组数：' . count($groups));
            foreach ($groups as $name => $items) {
                $this->line(sprintf('  %-24s %d', $name, count($items)));
            }
            return 0;
        }

        $this->info('路由总数：' . count($rows) . '，分组数：' . count($groups));
        $headers = array_map(static fn(string $key): string => self::COLUMNS[$key], $columns);

        foreach ($groups as $name => $items) {
            $this->line('');
            $this->line('── ' . $name . ' (' . count($items) . ') ──');
            $table = [];
            foreach ($items as [$route, $source]) {
                $table[] = $this->rowCells($route, $source, $columns, $registry);
            }
            $this->table($headers, $table);
        }

        return 0;
    }

    /**
     * 解析要显示的列（顺序即展示顺序）。
     *
     * @return list<string>
     */
    private function resolveColumns(string $spec, bool $rateLimit): array
    {
        $default = ['method', 'uri', 'name', 'middleware', 'source', 'action'];

        if ($spec === '') {
            return $rateLimit ? [...$default, 'ratelimit'] : $default;
        }

        $chosen = [];
        foreach (explode(',', $spec) as $raw) {
            $key = trim($raw);
            if ($key !== '' && isset(self::COLUMNS[$key])) {
                $chosen[] = $key;
            }
        }

        // 显式列里没有 ratelimit 但带了 --rate-limit，则追加到末尾。
        if ($rateLimit && !in_array('ratelimit', $chosen, true)) {
            $chosen[] = 'ratelimit';
        }

        return $chosen === [] ? $default : $chosen;
    }

    /**
     * 计算单条路由在所选列下的单元格。
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function rowCells(Route $route, string $source, array $columns, RouteRegistry $registry): array
    {
        $cells = [];
        foreach ($columns as $col) {
            $cells[] = match ($col) {
                'method' => implode('|', $route->getMethods()),
                'uri' => $route->getPattern(),
                'name' => $route->getName() ?? '',
                'middleware' => $this->middlewares($route),
                'source' => $source,
                'action' => $this->action($route),
                'ratelimit' => $this->rateLimitCol($route, $registry),
            };
        }

        return $cells;
    }

    /**
     * 把路由上的 #[RateLimit] 规则渲染为一行摘要（如 token_bucket:50/2.0）。
     */
    private function rateLimitCol(Route $route, RouteRegistry $registry): string
    {
        $rules = $registry->rateLimitsOf($route);
        if ($rules === []) {
            return '-';
        }

        return implode('; ', array_map(
            static fn(\Kode\Limiting\Attribute\RateLimit $r): string
                => $r->type->value . ':' . $r->capacity . '/' . $r->rate,
            $rules
        ));
    }

    /**
     * 分组键：按 URI 首段归类（root 归为 '/'），便于大项目折叠查看。
     */
    private function groupKey(Route $route): string
    {
        $seg = explode('/', trim($route->getPattern(), '/'))[0] ?? '';

        return $seg === '' ? '/' : $seg;
    }

    private function middlewares(Route $route): string
    {
        $list = [];
        foreach ($route->getMiddlewares() as $m) {
            $list[] = is_object($m) ? get_class($m) : (is_string($m) ? $m : 'callable');
        }

        return $list === [] ? '-' : implode(', ', $list);
    }

    private function action(Route $route): string
    {
        $h = $route->getHandler();
        if ($h instanceof \Closure) {
            return 'Closure';
        }
        if (is_array($h)) {
            $class = is_object($h[0]) ? get_class($h[0]) : $h[0];

            return $class . '::' . ($h[1] ?? '__invoke');
        }
        if (is_object($h)) {
            return get_class($h);
        }
        if (is_string($h)) {
            return $h;
        }

        return 'unknown';
    }
}
