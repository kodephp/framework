<?php

declare(strict_types=1);

namespace Kode\Framework\Plugin;

use Kode\Console\Kernel;
use Kode\DI\Container;
use Kode\Event\Dispatcher;
use Kode\Framework\Http\RouteRegistry;
use Kode\Framework\Scheduling\ScheduleDispatcher;
use Kode\Framework\Scheduling\ScheduledTask;
use Kode\Http\App;
use Kode\Http\Routing\Route;

/**
 * 插件管理器（框架薄封装）
 *
 * 逐个实例化并注册已解析的插件类。要加载哪些类由 PluginServiceProvider 决定，
 * 其真值源是 PluginDiscoverer::discover()（代码层事实）+ config/plugins.php
 * （声明/收窄），本类不自行扫描目录，避免产生第三份插件清单。
 *
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
     *
     * 参数顺序与本类的 bind() 一致（真实 id 在前），而 kode/di 的
     * Container::alias() 是 (alias, id)——委派时必须交换实参，否则别名与
     * 目标服务对调，$this->container->get($alias) 直接 ServiceNotFound。
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->container->alias($alias, $abstract);
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
     * 注册一条定时任务（兑现 PluginInterface::boot() 承诺的「注册定时任务」）。
     *
     * 与 #[Cron] 属性扫描互补：属性扫描是约定式（目录 + 类/方法标注），本方法是命令式
     * （在 register()/boot() 里直接登记），适合「处理器即插件自身方法」或需要运行时
     * 决定表达式/开关的场景。两者最终都进同一个 ScheduleDispatcher，schedule:list 可见。
     *
     * 处理器支持三种写法：
     *  - ['App\\Tasks\\SyncTask', 'handle']  类方法元组，走容器解析（支持构造/属性注入）
     *  - 'App\\Tasks\\SyncTask::handle'      类方法字符串（解析同上）
     *  - fn(): void 闭包                     内联处理器，不经容器直接调用
     *
     * 任务来源统一标记 plugin:<插件名>；任务名重复会显式抛错（调度器按名索引，
     * 静默覆盖会让其中一个任务永久失效）。
     *
     * @param array{0: class-string, 1: string}|\Closure|string $handler 处理器
     * @throws \InvalidArgumentException 处理器形态非法
     * @throws \RuntimeException 任务名与已注册任务冲突
     */
    public function addCron(
        string $expression,
        string|array|\Closure $handler,
        string $name = '',
        string $description = '',
        bool $cluster = false,
        bool $enabled = true,
    ): ScheduledTask {
        [$class, $method, $inline] = $this->normalizeCronHandler($handler);

        $name = $name !== '' ? $name : $this->current . '.' . $method;

        /** @var ScheduleDispatcher $dispatcher */
        $dispatcher = $this->container->get(ScheduleDispatcher::class);
        foreach ($dispatcher->registered() as $existing) {
            if ($existing->name === $name) {
                throw new \RuntimeException(sprintf(
                    'addCron(): 任务名「%s」已被占用（来源 %s），请改用唯一名称',
                    $name,
                    $existing->source
                ));
            }
        }

        $task = new ScheduledTask(
            class: $class,
            method: $method,
            expression: $expression,
            name: $name,
            description: $description !== '' ? $description : null,
            enabled: $enabled,
            cluster: $cluster,
            source: $this->cronSource(),
            handler: $inline,
        );

        $dispatcher->register([$task]);

        return $task;
    }

    /**
     * addCron() 任务的来源标签。
     *
     * 插件 register()/boot() 内调用时标记为 plugin:<插件名>；在插件生命周期之外直接调用
     * （$current 仍为 unknown）时标记为 app——避免产出无意义的 plugin:unknown。
     */
    private function cronSource(): string
    {
        return $this->current === 'unknown' ? 'app' : 'plugin:' . $this->current;
    }

    /**
     * 把 addCron() 的处理器归一为 [类, 方法, 内联闭包|null]。
     *
     * @param array|string|\Closure $handler 处理器
     * @return array{0: string, 1: string, 2: \Closure|null}
     * @throws \InvalidArgumentException 处理器形态非法
     */
    private function normalizeCronHandler(string|array|\Closure $handler): array
    {
        if ($handler instanceof \Closure) {
            // 展示用 class 固定为 Closure（schedule:list 可见其为内联处理器）：
            // 反查闭包归属类在 PHP 8.4 之前没有稳定公共 API（getClosureScopeName 8.4+），
            // 不值得为一个展示字段引入版本分支。invoke() 会短路直接调闭包，不会解析该值。
            return [\Closure::class, '__invoke', $handler];
        }

        if (is_array($handler)) {
            $class = $handler[0] ?? null;
            $method = $handler[1] ?? null;
            if (!is_string($class) || $class === '' || !is_string($method) || $method === '') {
                throw new \InvalidArgumentException(
                    'addCron(): 元组处理器须为 [类名, 方法名]，两项均须为非空字符串'
                );
            }

            return [$class, $method, null];
        }

        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            if ($class === '' || $method === '') {
                throw new \InvalidArgumentException(
                    sprintf('addCron(): 字符串处理器须为 "类名::方法名"，收到「%s」', $handler)
                );
            }

            return [$class, $method, null];
        }

        throw new \InvalidArgumentException(
            sprintf('addCron(): 字符串处理器须形如 "类名::方法名"，收到「%s」', $handler)
        );
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
