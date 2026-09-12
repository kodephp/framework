<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Plugin\PluginDiscoverer;
use Kode\Framework\Plugin\PluginManager;
use Kode\Framework\Providers\ServiceProvider;

/**
 * 插件服务提供者
 *
 * 唯一真值源收敛：实际加载哪些插件，一律经 {@see PluginDiscoverer} 解析，
 * config/plugins.php 只做「声明/收窄」，不再自持一份独立的插件清单。
 *
 * 两条加载路径：
 *   - config/plugins.php 的 `plugins` 为空 → 全量装载发现结果（按插件名排序，确定性）；
 *   - `plugins` 非空 → 按声明顺序装载，仅保留发现结果中确实存在的类。
 *
 * 漂移可查（不阻断启动）：
 *   PluginDiscoverer::undeclared($declared)     目录里有但框架不加载的插件
 *   PluginDiscoverer::missingDeclared($declared) 声明了但发现不到的类
 *
 * 插件可注册服务/路由/监听器/命令/定时任务，路由会打上 plugin:<name> 来源标签。
 */
final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(PluginManager::class, fn(): PluginManager => new PluginManager($this->container));
    }

    public function boot(): void
    {
        /** @var PluginManager $manager */
        $manager = $this->container->get(PluginManager::class);

        /** @var array<int, class-string|string> $declared */
        $declared = (array) $this->config('plugins.plugins', []);

        $classes = $this->resolveClasses($declared);

        // 声明了插件却一个都发现不到 = 静默漂移，必须喊出来。
        // 典型触发：插件目录不可读、文件被打包排除、或 baseDir 解析到错误位置。
        // 历史上这里会无声跳过，表现为「config 里写着插件，但 /api/<name> 全部 404」。
        if ($declared !== [] && $classes === []) {
            $missing = implode(', ', array_map('strval', $declared));
            trigger_error(sprintf(
                '插件加载为空：config/plugins.php 声明了 %d 个插件但发现器一个都没匹配到（声明：%s；扫描目录：%s）。'
                . '常见原因：插件目录未打进产物、baseDir 解析错误、或插件类无法 autoload。',
                count($declared),
                $missing,
                PluginDiscoverer::baseDir()
            ), \E_USER_WARNING);
        }

        if ($classes !== []) {
            $manager->load($classes);
        }
    }

    /**
     * 把「声明清单」解析为「实际要加载的类清单」。
     *
     * 所有判定都基于 PluginDiscoverer::discover()——框架与应用侧看到同一份插件事实，
     * 不存在第二份独立清单可供漂移。
     *
     * @param array<int, class-string|string> $declared
     * @return array<int, class-string>
     */
    private function resolveClasses(array $declared): array
    {
        $discovered = PluginDiscoverer::discover();

        if ($declared === []) {
            ksort($discovered);

            return array_values($discovered);
        }

        $known = $discovered;
        $classes = [];
        foreach ($declared as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            // 只加载发现结果中确实存在的类：避免 config 里写错类名后
            // PluginManager::load() 静默跳过，应用后台却把它当成已装载插件。
            if (in_array($class, $known, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}

