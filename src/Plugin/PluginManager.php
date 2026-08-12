<?php

declare(strict_types=1);

namespace Kode\Framework\Plugin;

use Kode\Console\Kernel;
use Kode\DI\Container;
use Kode\Event\Dispatcher;
use Kode\Framework\Http\RouteRegistry;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 插件管理器（框架薄封装）
 *
 * 读取 config/plugins.php 的 plugins 列表，逐个实例化并注册。
 * 插件通过本管理器提供的语义化方法完成注册，与「手动在 Provider/bootstrap 里写」
 * 等价，但更内聚、可被 route:list 按来源（plugin:<name>）聚合展示。
 *
 * 设计取舍：插件不引入独立的「插件生命周期/钩子总线」，而是复用框架既有的
 * 服务提供者、路由、事件、控制台机制——保持薄核，不重复造轮子（对齐 webman 思路）。
 */
final class PluginManager
{
    /** @var array<string, PluginInterface> name => 实例 */
    private array $plugins = [];

    /** 当前正在 register/boot 的插件名（供 addRoute 打来源标签）。 */
    private string $current = 'unknown';

    /**
     * @param array<int, class-string<PluginInterface>> $classes
     */
    public function __construct(
        private Container $container,
        private array $classes = [],
    ) {
    }

    /**
     * 加载全部插件（register 先于 boot）。
     *
     * @param array<int, class-string<PluginInterface>> $classes
     */
    public function load(array $classes): void
    {
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            /** @var PluginInterface $plugin */
            $plugin = new $class();
            $this->plugins[$plugin->name()] = $plugin;
            $this->current = $plugin->name();
            $plugin->register($this);
        }

        foreach ($this->plugins as $plugin) {
            $this->current = $plugin->name();
            $plugin->boot($this);
        }
    }

    /**
     * @return array<string, PluginInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }

    // ---- 插件可用的注册接口 ----

    /**
     * 绑定一个单例服务到容器。
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @param callable(Container): T $factory
     */
    public function bind(string $id, callable $factory): void
    {
        $this->container->singleton($id, $factory);
    }

    /**
     * 为已绑定服务注册别名（助手/门面友好名）。
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->container->alias($abstract, $alias);
    }

    /**
     * 注册一条路由，并打上 plugin:<name> 来源标签（route:list 可见）。
     *
     * @param string|array<int, string> $methods
     * @param mixed $handler 闭包 / [控制器类, 方法] / 控制器实例方法
     */
    public function addRoute(string $name, string|array $methods, string $pattern, mixed $handler): Route
    {
        /** @var App $app */
        $app = $this->container->get(App::class);
        $route = $app->getRouter()->add($methods, $pattern, $handler);
        if ($name !== '') {
            $route->name($name);
        }

        /** @var RouteRegistry $registry */
        $registry = $this->container->get(RouteRegistry::class);
        $registry->tag($route, 'plugin:' . $this->lastPluginName());

        return $route;
    }

    /**
     * 注册一个事件监听器（对齐 config/event.php 的 listeners）。
     *
     * @param callable|class-string|array{0: class-string, 1: string} $handler
     */
    public function addListener(string $event, mixed $handler): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->get(Dispatcher::class);
        $dispatcher->listen($event, $handler);
    }

    /**
     * 注册一条控制台命令。
     *
     * @param class-string $command
     */
    public function addCommand(string $command): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->container->get(Kernel::class);
        $kernel->add($command);
    }

    /**
     * 从容器解析服务（插件内部可用）。
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return T
     */
    public function make(string $id): object
    {
        return $this->container->get($id);
    }

    /**
     * 当前正在 register/boot 的插件名（供 addRoute 打来源标签）。
     */
    private function lastPluginName(): string
    {
        return $this->current;
    }
}
