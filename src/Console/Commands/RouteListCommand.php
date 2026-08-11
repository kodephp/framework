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
 */
#[AsCommand(
    name: 'route:list',
    description: '列出全部路由（按分组/来源聚合，支持过滤）',
    usage: 'route:list [--compact] [--group=NAME] [--method=METHOD] [--source=LABEL]',
)]
final class RouteListCommand extends Command
{
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
        foreach ($groups as $name => $items) {
            $this->line('');
            $this->line('── ' . $name . ' (' . count($items) . ') ──');
            $table = [];
            foreach ($items as [$route, $source]) {
                $table[] = [
                    implode('|', $route->getMethods()),
                    $route->getPattern(),
                    $route->getName() ?? '',
                    $this->middlewares($route),
                    $source,
                    $this->action($route),
                ];
            }
            $this->table(['Method', 'URI', 'Name', 'Middleware', 'Source', 'Action'], $table);
        }

        return 0;
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
