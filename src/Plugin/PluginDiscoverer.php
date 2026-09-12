<?php

declare(strict_types=1);

namespace Kode\Framework\Plugin;

/**
 * 插件发现器（框架级，插件机制的唯一真值源）。
 *
 * 为什么需要它：框架的插件加载（PluginServiceProvider 读 config/plugins.php）
 * 与应用层的插件目录扫描（glob plugins/*Plugin.php）是两条独立路径，
 * 两边各自"知道"哪些插件存在——这是「声明⟺实现漂移」的经典来源：
 * 目录里躺着一个插件文件，框架不加载它，但应用后台把它当成已装载插件展示与校验。
 *
 * 本类把「代码层到底有哪些插件」收敛为单一入口，两侧都从这里取：
 *   - 框架侧 PluginServiceProvider 用它决定实际加载哪些类；
 *   - 应用侧用它做安装/审计/依赖/打包（插件 zip 清单）。
 *
 * 发现规则（与历史行为一致，不引入语义变更）：
 *   1. 扫描 `<baseDir>/*Plugin.php`，类名 = `Kode\Plugins\<文件名>`；
 *   2. 类必须存在且可实例化（无参构造），否则跳过；
 *   3. 必须有 `name()` 方法，返回值必须匹配 NAME_PATTERN；
 *   4. 同名插件以字典序靠前的文件为准（glob 有序，先写者胜，后写不覆盖）。
 *
 * 注意区分两条正交的概念：
 *   - **发现**（本类）：代码层存在哪些插件类——静态事实，进程内缓存；
 *   - **加载**（PluginServiceProvider）：本进程实际 register/boot 了哪些——
 *     由 config/plugins.php 的声明 + 本类的发现结果共同决定。
 */
class PluginDiscoverer
{
    /**
     * 插件名格式：小写字母/数字/下划线，2-32 位。
     *
     * 与路由前缀 `/api/<name>/*`、菜单 path、依赖声明的 name 字段共享同一约束——
     * 改这里必须同步评估路由门禁与依赖图的兼容性。
     */
    public const NAME_PATTERN = '/^[a-z0-9_]{2,32}$/';

    /** 插件类命名空间前缀（PSR-4：plugins/ → Kode\Plugins\）。 */
    public const NAMESPACE_PREFIX = 'Kode\\Plugins\\';

    /** 插件类文件名后缀（扫描 `*Plugin.php`）。 */
    public const FILE_SUFFIX = 'Plugin.php';

    /** 进程内发现缓存：name => class。 */
    private static ?array $discovered = null;

    /** 进程内插件根目录缓存（baseDir 可在测试中覆盖）。 */
    private static ?string $baseDirOverride = null;

    /**
     * 插件根目录（默认 `<项目根>/plugins`）。测试可经 useBaseDir() 覆盖。
     *
     * 解析顺序（逐级降级，任一级命中即返回）。必须在**启动期**可用——
     * PluginServiceProvider::boot() 阶段 App 单例可能尚未就绪，
     * 因此不依赖 config() 全局函数，也不依赖容器。
     */
    public static function baseDir(): string
    {
        if (self::$baseDirOverride !== null) {
            return self::$baseDirOverride;
        }

        // 1. KODE_PROJECT_ROOT：骨架入口薄壳在转发前定义，最权威。
        if (defined('KODE_PROJECT_ROOT')) {
            $root = KODE_PROJECT_ROOT;
            if (is_string($root) && $root !== '' && is_dir($root . '/plugins')) {
                return $root . '/plugins';
            }
        }
        // 2. App 已引导：走框架统一的 basePath 解析。
        if (\function_exists('base_path')) {
            $dir = base_path('plugins');
            if (is_string($dir) && $dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }
        // 3. 自本类文件上溯找 plugins/ 目录（vendor 安装下深 4 层，仓库内只有 1 层，
        //    故不写死 dirname 次数）。
        $dir = __DIR__;
        for ($i = 0; $i < 7 && is_dir($dir); $i++) {
            if (is_dir($dir . '/plugins')) {
                return $dir . '/plugins';
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return 'plugins';
    }

    /** 覆盖插件根目录（测试用）；传 null 恢复默认。 */
    public static function useBaseDir(?string $dir): void
    {
        self::$baseDirOverride = $dir;
        self::flush();
    }

    /**
     * 发现代码层的全部插件。
     *
     * 进程内静态缓存（插件增删需重启服务，与路由/插件启动期加载一致）。
     *
     * @return array<string, class-string> name => class（非法名跳过）
     */
    public static function discover(): array
    {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

        return self::$discovered = self::scan();
    }

    /**
     * 已声明（config/plugins.php）之外的发现结果——漂移提示。
     *
     * 非空即表示「目录里有插件文件但框架不会加载它」：
     * 应用后台若把它展示成已装载插件，就是又一处声明⟺实现漂移。
     * 本方法只报告，不改变加载行为。
     *
     * @param list<class-string|string> $declared config/plugins.php 声明的类名
     * @return array<string, class-string> name => class
     */
    public static function undeclared(array $declared = []): array
    {
        $declaredSet = [];
        foreach ($declared as $class) {
            if (is_string($class) && $class !== '') {
                $declaredSet[$class] = true;
            }
        }

        $out = [];
        foreach (self::discover() as $name => $class) {
            if (!isset($declaredSet[$class])) {
                $out[$name] = $class;
            }
        }

        return $out;
    }

    /**
     * 已声明但不存在（类不存在或不在发现结果中）——反向漂移。
     *
     * @param list<class-string|string> $declared
     * @return list<string> 声明的类名列表
     */
    public static function missingDeclared(array $declared = []): array
    {
        $known = array_values(self::discover());
        $out = [];
        foreach ($declared as $class) {
            if (is_string($class) && $class !== '' && !in_array($class, $known, true)) {
                $out[] = $class;
            }
        }

        return $out;
    }

    /** 使发现缓存失效（测试用；新增/删除插件文件后需调用）。 */
    public static function flush(): void
    {
        self::$discovered = null;
    }

    /**
     * 列出插件根目录下匹配 `*Plugin.php` 的文件名（不含路径）。
     *
     * 结果按文件名排序——glob() 原本就返回有序结果，改 DirectoryIterator 后
     * 文件系统返回顺序不定（尤其 phar 归档按写入序），必须显式排序，
     * 才能保证「同名插件以字典序靠前者为准」这条约定在打包产物里同样成立。
     *
     * 目录打不开时静默返回空表（与历史 is_dir() 判定的语义一致）。
     *
     * @return list<string> 文件名（如 'NoticePlugin.php'）
     */
    private static function listPluginFiles(string $dir): array
    {
        $suffix = self::FILE_SUFFIX;
        $files = [];

        try {
            $it = new \DirectoryIterator(rtrim($dir, '/\\'));
        } catch (\UnexpectedValueException) {
            return $files;
        }

        foreach ($it as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (str_ends_with($name, $suffix)) {
                $files[] = $name;
            }
        }

        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * 实际执行目录扫描。
     *
     * 用 DirectoryIterator 而非 glob()——这是硬约束，不是风格偏好：
     * glob() 不支持流封装器（`phar://`、`zip://`、自定义 stream），
     * 打包产物里 baseDir() 会正确解析成 `phar://kode.phar/plugins`，
     * 但 glob() 对 phar 路径**静默返回空数组（不是 false）**，
     * 结果是「发现 0 个插件」且无任何报错——所有插件在二进制/PHAR 分发行里
     * 无声消失，而 config/plugins.php 里明明声明着它们。
     * DirectoryIterator 走 SplFileInfo 流接口，phar 与本地文件系统行为一致。
     *
     * @return array<string, class-string>
     */
    private static function scan(): array
    {
        $dir = self::baseDir();
        $found = [];

        if (!is_dir($dir)) {
            return $found;
        }

        $files = self::listPluginFiles($dir);

        foreach ($files as $file) {
            $class = self::NAMESPACE_PREFIX . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            try {
                $plugin = new $class();
            } catch (\Throwable) {
                continue;
            }
            if (!method_exists($plugin, 'name')) {
                continue;
            }
            try {
                $name = (string) $plugin->name();
            } catch (\Throwable) {
                continue;
            }
            if (!preg_match(self::NAME_PATTERN, $name)) {
                continue;
            }
            // 先写者胜：同名插件不覆盖，避免扫描顺序带来的静默不确定性。
            if (!isset($found[$name])) {
                $found[$name] = $class;
            }
        }

        return $found;
    }
}
