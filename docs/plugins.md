# 插件（对齐 webman，轻量实现）

插件是一段「可独立开关的能力包」：一个实现 `Kode\Framework\Plugin\PluginInterface` 的类，
在 `config/plugins.php` 的 `plugins` 数组里声明即可启用。框架启动期会实例化并依次调用
`register()` / `boot()`，插件通过 `PluginManager` 注册路由 / 服务 / 监听器 / 命令。

示例插件（`app/Plugins/DemoPlugin.php`）：

```php
use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

final class DemoPlugin implements PluginInterface
{
    public function name(): string { return 'demo'; }

    public function register(PluginManager $manager): void
    {
        $manager->addRoute('demo.hello', 'GET', '/plugin/demo', fn (): array => [
            'plugin' => 'demo', 'hello' => 'world',
        ]);
        $manager->bind('demo.service', fn (): DemoService => new DemoService());
    }

    public function boot(PluginManager $manager): void {}
}
```

开启（`config/plugins.php`）：

```php
return ['plugins' => [\App\Plugins\DemoPlugin::class]];
```

`PluginManager` 提供的注册接口：

| 方法 | 作用 |
| --- | --- |
| `addRoute($name, $methods, $pattern, $handler)` | 注册路由，自动打 `plugin:<name>` 来源标签 |
| `bind($id, $factory)` | 绑定一个单例服务到容器 |
| `alias($abstract, $alias)` | 为服务注册别名 |
| `addListener($event, $handler)` | 注册事件监听器 |
| `addCommand($class)` | 注册控制台命令 |
| `make($id)` | 从容器解析服务 |

插件路由可用 `bin/kode route:list --source=plugin:demo` 单独查看；插件定时器放到
`plugins/<name>/src/Tasks` 下可被 `bin/kode cron`（开启 `discover_plugins`）自动扫描。

> 设计取舍：插件不引入独立的「生命周期/钩子总线」，而是复用框架既有的
> 服务提供者、路由、事件、控制台机制——保持薄核，不重复造轮子（与 webman 思路一致）。

---

