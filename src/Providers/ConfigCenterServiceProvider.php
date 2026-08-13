<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Core\Config\Config;
use Kode\Event\Dispatcher;
use Kode\Framework\Config\ConfigCenter;
use Kode\Framework\Config\ConfigSource;

/**
 * 配置中心服务提供者（薄壳层）。
 *
 * 薄壳立场：
 *   - 框架不内置远程中心客户端，只提供「可插拔配置源」抽象 + 运行时热重载 + 事件；
 *   - 启动期 seed() 把 sources 合并进 Config，使中心值覆盖 config/*.php 文件值；
 *   - 运行期由 config:center:reload 命令或应用侧 watch 触发 reload()。
 *
 * 顺序要求：本 Provider 必须在 ConfigServiceProvider（必填校验）与其他读配置的
 * Provider 之前 boot，否则中心覆盖值来不及生效。故在 Application::$defaults 中置于首位之前。
 */
final class ConfigCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 单例在 boot() 中按「已启用的源」实例化并 seed，这里不提前绑定以免空壳。
    }

    public function boot(): void
    {
        if (!(bool) $this->config('center.enabled', false)) {
            return;
        }

        /** @var Config $config */
        $config = $this->container->make(Config::class);

        $sources = $this->buildSources();
        if ($sources === []) {
            return;
        }

        // 事件系统在启动顺序上可能晚于本 Provider，故用可选 callable 延迟派发，
        // reload() 真正执行时（命令/运行期）事件早已就绪。
        $dispatcher = $this->container->bound(Dispatcher::class)
            ? fn (object $event): object => $this->container->get(Dispatcher::class)->dispatch($event)
            : null;

        $center = new ConfigCenter($config, $sources, $dispatcher);
        $center->seed();

        $this->container->instance(ConfigCenter::class, $center);
        $this->container->alias('config.center', ConfigCenter::class);
    }

    /**
     * 根据 config/center.php 的 sources 列表实例化配置源。
     *
     * 支持两种声明：
     *   - 字符串类名（无参构造）；
     *   - ['class' => X, 'config' => [...]]（数组传给构造）。
     *
     * @return array<int, ConfigSource>
     */
    private function buildSources(): array
    {
        /** @var array<int, mixed> $defs */
        $defs = (array) $this->config('center.sources', []);
        $sources = [];

        foreach ($defs as $def) {
            if (is_string($def) && class_exists($def)) {
                $instance = new $def();
            } elseif (is_array($def) && isset($def['class']) && class_exists((string) $def['class'])) {
                /** @var class-string<ConfigSource> $cls */
                $cls = (string) $def['class'];
                $instance = new $cls((array) ($def['config'] ?? []));
            } else {
                continue;
            }

            if ($instance instanceof ConfigSource) {
                $sources[] = $instance;
            }
        }

        return $sources;
    }
}
