<?php

declare(strict_types=1);

namespace Kode\Framework\Packaging;

/**
 * 系统级打包：把整个站点打成单文件分发行。
 *
 * 三档形态（能力递增，代价递增）：
 *   A. PHAR   build/kode.phar   需目标机有 PHP        任意平台
 *   B. 二进制 build/kode.bin    无需 PHP（内嵌 micro.sfx）仅 x86_64 Linux
 *   C. 镜像   Dockerfile        PHP 在镜像内         任意 Linux
 *
 * 与插件级打包（PluginPackager，单插件 zip）职责分离：本类只负责「整个系统」。
 *
 * 工程约束（全部经实测，改动前先读）：
 *   1. `phar.readonly` 是 PHP_INI_SYSTEM——进程内 `ini_set()` 返回 false、
 *      值不变。只能用 `php -d phar.readonly=0 kode pack:phar` 启动；
 *      本类检测到只读只报错、绝不假装能改。
 *   2. `Phar::mapPhar()` 的别名必须等于 phar 文件的 basename，否则致命
 *      「implicit alias "kode.phar" under different alias "kode"」。
 *      因此 stub() 必须由别名生成，不能写死。
 *   3. `realpath()` 不支持 phar:// 流（恒返回 false）。骨架入口 kode 的防递归
 *      守卫若用 `realpath() !== realpath()` 会退化成 `false !== false` = false，
 *      导致打包产物永远找不到框架 CLI。骨架侧已改为 phar 感知（见 kode::self_is）。
 *   4. 构建必须走「暂存目录 + buildFromDirectory()」。逐文件 `addFile()` 在默认
 *      128M memory_limit 下、对 44MB / 6152 文件的 vendor 会被 OOM 杀掉（SIGKILL）。
 *      暂存 + buildFromDirectory() 在 512M 下稳定完成。
 *   5. 排除 dev 依赖目录（vendor/phpunit 等）后，composer 自动加载表里指向被排除
 *      文件的条目会在 bootstrap 被**硬 require**，直接致命
 *      （实测：myclabs/deep-copy/src/DeepCopy/deep_copy.php）。
 *      关键：Composer 2 的真正来源是 `autoload_static.php` 的 `ComposerStaticInit*::$files`
 *      /`::$classMap`——`autoload_real.php` 完全不读 `autoload_files.php`，只重写后者
 *      等于没改（实测踩过，包内依旧 require myclabs）。必须三份一起重写：
 *      autoload_static.php（$files + $classMap）、autoload_files.php、autoload_classmap.php。
 *      重写只按**实际文件集**过滤，不硬编码黑名单；输出路径用 __DIR__ 相对形式，
 *      因为暂存目录是临时的、绝对路径打进包必然失效。
 *   6. 包内文件存在性检查必须用 `file_exists('phar://<绝对路径>/…')`。
 *      两个实测失败形态：`is_file('kode.phar/kode')` 恒 false（phar 流的 url_stat
 *      不认裸 URL 形式）；`phar://kode.phar/kode` 只按 cwd 解析裸文件名，cwd 不在
 *      产物目录时同样 false。必须显式 phar:// 前缀 + 绝对路径。
 *   7. `Phar::getSignature()` 在 PHP 8.0+ 返回 **array**（hash/hash_type），
 *      不是对象——`->getType()` 直接致命。本类对 array / 对象两种形态都兼容。
 *      `Phar` 无 `isValid()`；签名真伪由 `new \Phar()` 与遍历时的内部校验兜住
 *      （篡改即抛 UnexpectedValueException）。
 *   8. 别用 `foreach($phar)` 数文件：本 PHP 构建的 Phar 迭代器只产出顶层条目
 *      （实测 13 项，而实际 4972 个），会严重低估。计数用 `$phar->count()`，
 *      取桩用 `$phar->getStub()`（不要用 PharData 的 getContents()，Phar 没有）。
 *      同一构建里 Phar::fileSize()/getCurrentFileInfo()/buildFromDirectory($flags)
 *      也不存在——凡涉及 Phar API 一律以实测为准，不照抄手册。
 *
 * 只读原则：pack() 不修改项目源文件，只读源 + 写 build/ 与 sys temp。
 *
 * 分层契约（v1.4.2 起）：本类是**框架级打包引擎**，只认两样东西——
 *   - 项目根（projectRoot()，子类可覆盖）；
 *   - 项目根下的 config/packaging.php（缺省时用下方内置默认值）。
 * 不读任何业务表、不碰插件状态机。应用需要定制时**继承本类**，只覆盖
 * projectRoot() / config() 这两个静态钩子即可，不要在子类里复制引擎逻辑。
 */
class Packager
{
    /** 二进制 magic（workerman custom-ini 头约定）。 */
    private const BIN_INI_MAGIC = "\xfd\xf6\x69\xe6";

    /** 进程内配置覆盖（测试 / 脚本用，不落盘）。 */
    private static array $overrides = [];

    /** 当前生效的打包器实现类名（默认本类；应用注册子类后转由子类驱动）。 */
    private static string $driverClass = self::class;
    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $user = static::userConfig();
        unset($user['packager']);
        $defaults = [
            'build_dir'            => 'build',
            'phar_filename'        => 'kode.phar',
            'bin_filename'         => 'kode.bin',
            'signature_algorithm'  => \Phar::SHA256,
            'openssl_private_key'  => null,
            'memory_limit_mb'      => 512,
            'php_version'          => null,
            'micro_sfx_base'       => 'https://download.workerman.net/php8.microsfx',
            'include_dirs'         => ['app', 'config', 'database', 'lang', 'plugins', 'public', 'vendor'],
            'include_files'        => ['kode', 'composer.json', 'composer.lock', 'LICENSE', 'README.md', '.env.example'],
            'exclude_dirs'         => [],
            'exclude_files'        => [],
            'exclude_top_files'    => [],
            'exclude_ext'          => [],
            'must_include'         => [],
            'runtime_dirs'         => [],
            'custom_ini'           => null,
        ];
        $cfg = array_merge($defaults, $user);
        $cfg['php_version'] ??= substr(PHP_VERSION, 0, 3);

        // 进程内覆盖（测试 / 脚本用，不落盘）。
        if (self::$overrides !== []) {
            $cfg = array_merge($cfg, self::$overrides);
        }

        return $cfg;
    }

    /**
     * 读取 config/packaging.php 的原始用户配置（未合并默认值、未叠进程内覆盖）。
     *
     * 独立于 config() 存在的原因：driver() 需要在 config() 把 'packager' 键
     * unset 掉之前拿到它——否则「配置声明继承实现」这条解析路径永远走不通。
     *
     * @return array<string, mixed>
     */
    private static function userConfig(): array
    {
        $file = static::projectRoot() . '/config/packaging.php';

        return is_file($file) ? (array) include $file : [];
    }

    /** 进程内配置覆盖（测试 / 脚本用，不落盘）。传 [] 清除。 */
    public static function override(array $config): void
    {
        self::$overrides = $config;
    }

    /**
     * 当前生效的打包器实现类名。
     *
     * 解析优先级（逐级降级）：
     *   1. useClass() 显式注册——最高优先级，进程内生效，测试 / 多打包器场景用；
     *   2. config/packaging.php 的 'packager' 键——应用声明自己的继承实现；
     *   3. 本类（框架内置实现）。
     *
     * 命令层统一经本方法取类名再调用（`$packer::pack()`），保证「应用继承即生效」，
     * 不必为每个命令写子类。解析出的类名会缓存，避免每次调用都重读配置文件。
     *
     * @return class-string<self>
     */
    public static function driver(): string
    {
        if (self::$driverClass !== self::class) {
            return self::$driverClass;
        }

        $candidate = static::userConfig()['packager'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            if (class_exists($candidate)) {
                if ($candidate === self::class || is_subclass_of($candidate, self::class)) {
                    return self::$driverClass = $candidate;
                }
                throw new \InvalidArgumentException(
                    sprintf('config/packaging.php 的 packager「%s」不是 %s 或其子类', $candidate, self::class)
                );
            }
            throw new \ClassNotFoundException(
                sprintf('config/packaging.php 的 packager「%s」类不存在，请检查命名空间与 autoload', $candidate)
            );
        }

        return self::$driverClass;
    }

    /**
     * 注册打包器实现（须为本类或其子类）；传本类名可恢复默认。
     *
     * 与 config/packaging.php 的 'packager' 键二选一即可，显式注册优先。
     *
     * @param class-string $class
     */
    public static function useClass(string $class): void
    {
        if ($class !== self::class && !is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException(
                sprintf('useClass(): %s 不是 %s 或其子类', $class, self::class)
            );
        }
        self::$driverClass = $class;
    }

    /**
     * 项目根（打包对象）。子类可覆盖。
     *
     * 解析顺序（逐级降级，任一级命中即返回）：
     *   1. KODE_PROJECT_ROOT 常量——骨架入口薄壳在转发前定义，最权威；
     *   2. app()->basePath()——框架已引导（走 ConsoleServiceProvider 的 console 命令时）；
     *   3. 自本类文件上溯——同时具备 app/ + config/ + vendor/autoload.php 的目录。
     *
     * 不能写死 dirname 次数：本类位于 src/Packaging/ 下，vendor 安装时
     * __DIR__ 深 4 层、框架仓库内只有 2 层；phpunit 环境下前两级都不成立，
     * 实测错根表现为「产物路径变成 src/var/folders/.../kode.bin，config/packaging.php 读不到」。
     */
    public static function projectRoot(): string
    {
        if (defined('KODE_PROJECT_ROOT')) {
            $root = KODE_PROJECT_ROOT;
            if (is_string($root) && $root !== '' && is_dir($root)) {
                return $root;
            }
        }
        if (\function_exists('base_path')) {
            $base = base_path();
            if (is_string($base) && $base !== '' && is_dir($base)) {
                return $base;
            }
        }

        $dir = __DIR__;
        for ($i = 0; $i < 7 && is_dir($dir); $i++) {
            if (is_dir($dir . '/app') && is_dir($dir . '/config') && is_file($dir . '/vendor/autoload.php')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return dirname(dirname(__DIR__));
    }

    public static function buildDir(): string
    {
        $dir = trim((string) static::config()['build_dir']);
        if ($dir === '') {
            return static::projectRoot();
        }
        // 绝对路径直接使用（测试/脚本常指向 sys temp）；
        // 相对路径才挂到项目根。原先无条件拼接会把 /var/... 变成 app/var/...（实测踩过）。
        if (str_starts_with($dir, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)) {
            return rtrim($dir, '/\\');
        }

        return static::projectRoot() . '/' . rtrim($dir, '/\\');
    }

    /**
     * 打包前置检查。返回阻塞项列表（空 = 可打包）。
     *
     * 全部为「不可绕过」的环境事实，不猜、不自动修复。
     *
     * @return list<string>
     */
    public static function precheck(bool $forBinary = false): array
    {
        $issues = [];

        if (!extension_loaded('phar')) {
            $issues[] = '缺少 phar 扩展（php -m | grep phar）';
        }
        if (ini_get('phar.readonly') !== '0') {
            $issues[] = 'phar.readonly=1——该值属 PHP_INI_SYSTEM，进程内无法修改。'
                . ' 请用 `php -d phar.readonly=0 kode pack:phar` 重新执行';
        }
        if (static::config()['signature_algorithm'] === \Phar::OPENSSL) {
            if (!extension_loaded('openssl')) {
                $issues[] = 'signature_algorithm=OPENSSL 但未加载 openssl 扩展';
            }
            $key = static::config()['openssl_private_key'];
            if (!is_string($key) || $key === '' || !is_file($key)) {
                $issues[] = 'signature_algorithm=OPENSSL 但 openssl_private_key 无效：' . var_export($key, true);
            }
        }

        if ($forBinary) {
            $issues = array_merge($issues, self::binaryPlatformIssues());
        }

        return $issues;
    }

    /**
     * 二进制档位平台约束。实测：本机 arm64 macOS，无法产出可用二进制。
     *
     * 二进制 = php8.x.micro.sfx（静态 PHP 运行时）+ phar 拼接，
     * micro.sfx 仅有 x86_64 Linux 构建；且二进制不支持 Swoole 协程。
     *
     * @return list<string>
     */
    public static function binaryPlatformIssues(): array
    {
        $issues = [];
        if (PHP_OS_FAMILY !== 'Linux') {
            $issues[] = '二进制档位仅支持 Linux（当前：' . PHP_OS_FAMILY . '）——'
                . ' php8.x.micro.sfx 没有 macOS/Windows 构建';
        }
        if (php_uname('m') !== 'x86_64') {
            $issues[] = '二进制档位仅支持 x86_64 架构（当前：' . php_uname('m') . '）';
        }
        return $issues;
    }

    /**
     * 引导桩：phar 的执行入口。
     *
     * 两点必须做对（见类注释 2）：
     *   - mapPhar() 别名 = phar basename；
     *   - require 路径同样用该别名。
     *
     * 不在此处定义 KODE_PROJECT_ROOT：让它由骨架 kode 以 __DIR__ 定义，
     * phar 内 __DIR__ 恰为 `phar://<alias>`，框架据此解析到包内的
     * vendor/autoload.php（is_dir/is_file 对 phar:// 均返回 true，实测）。
     * 部署约定：.env 以环境变量提供（12-factor），storage 由 init 创建于工作目录。
     */
    public static function stub(string $alias): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

/*
 * Kode 打包产物引导桩（自动生成，勿手改）。
 *
 * 部署约定：.env 不进包（含密钥），请以环境变量提供（12-factor）；
 * storage/ 与日志在首次运行于工作目录创建。
 */
if (!defined('IN_KODE_PHAR')) {
    define('IN_KODE_PHAR', true);
}
Phar::mapPhar('<<ALIAS>>');
require 'phar://<<ALIAS>>/kode';

__HALT_COMPILER();
PHP
            . "\n";
    }

    /** 渲染后的桩（别名已代入）。 */
    public static function renderedStub(string $alias): string
    {
        return str_replace('<<ALIAS>>', $alias, self::stub($alias));
    }

    /**
     * 解析应进包的相对路径清单（纯计算，不写盘）。
     *
     * 规则：include_dirs / include_files 白名单 → 依次扣掉 exclude_dirs /
     * exclude_files / exclude_top_files / exclude_ext。白名单优先于排除表。
     *
     * @return list<string> 正斜杠相对路径，排序稳定
     */
    public static function collect(): array
    {
        $cfg = static::config();
        $root = static::projectRoot();
        // 归一化为「无前导/尾随斜杠 + 尾部带 /」的匹配前缀。
        // 用 trim 而非 array_map('ltrim', …, '/\\')：array_map 的附加参数必须是数组，
        // 传标量直接 TypeError（实测踩过）。
        $excludeDirs = array_map(
            static fn($d) => trim((string) $d, '/\\') . '/',
            (array) $cfg['exclude_dirs'],
        );
        $excludeBasename = array_fill_keys((array) $cfg['exclude_files'], true);
        $excludeTop = array_fill_keys((array) $cfg['exclude_top_files'], true);
        $excludeExt = array_fill_keys(array_map('strtolower', (array) $cfg['exclude_ext']), true);

        $out = [];

        foreach ((array) $cfg['include_dirs'] as $dir) {
            $abs = $root . '/' . $dir;
            if (!is_dir($abs)) {
                continue;
            }
            $ri = new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS);
            foreach (new \RecursiveIteratorIterator($ri) as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = self::rel($root, $file->getPathname());
                if (self::isExcluded($rel, $excludeDirs, $excludeBasename, $excludeExt)) {
                    continue;
                }
                $out[$rel] = true;
            }
        }

        foreach ((array) $cfg['include_files'] as $name) {
            $abs = $root . '/' . $name;
            if (!is_file($abs)) {
                continue;
            }
            $rel = $name;
            if (isset($excludeTop[$rel]) || isset($excludeBasename[basename($rel)])) {
                continue;
            }
            $ext = strtolower(pathinfo($rel, \PATHINFO_EXTENSION));
            if (isset($excludeExt[$ext])) {
                continue;
            }
            $out[$rel] = true;
        }

        $paths = array_keys($out);
        sort($paths, \SORT_STRING);

        return $paths;
    }

    /**
     * 判定相对路径是否被排除。
     *
     * @param list<string>      $excludeDirs     前缀（尾部带 /）
     * @param array<string,bool> $excludeBasename
     * @param array<string,bool> $excludeExt
     */
    private static function isExcluded(string $rel, array $excludeDirs, array $excludeBasename, array $excludeExt): bool
    {
        foreach ($excludeDirs as $prefix) {
            if (str_starts_with($rel . '/', $prefix)) {
                return true;
            }
        }
        if (isset($excludeBasename[basename($rel)])) {
            return true;
        }
        $ext = strtolower(pathinfo($rel, \PATHINFO_EXTENSION));
        if ($ext !== '' && isset($excludeExt[$ext])) {
            return true;
        }

        return false;
    }

    private static function rel(string $root, string $abs): string
    {
        return str_replace('\\', '/', substr($abs, strlen(rtrim($root, '/\\')) + 1));
    }

    /**
     * 阶段一：把应进包的文件复制到暂存目录。
     *
     * 返回 [暂存目录, 相对路径清单, autoload_files 被剔除条目]。
     * 暂存目录由调用方清理。
     *
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    public static function stage(?string $tempRoot = null): array
    {
        $paths = self::collect();
        $root = static::projectRoot();
        $stage = rtrim((string) ($tempRoot ?? sys_get_temp_dir()), '/\\')
            . '/kode_stage_' . bin2hex(random_bytes(4));
        if (!@mkdir($stage, 0755, true)) {
            throw new \RuntimeException("无法创建暂存目录：{$stage}");
        }

        foreach ($paths as $rel) {
            $src = $root . '/' . $rel;
            $dst = $stage . '/' . $rel;
            @mkdir(dirname($dst), 0755, true);
            if (!@copy($src, $dst)) {
                throw new \RuntimeException("暂存复制失败：{$rel}");
            }
        }

        // 约束 5：按实际文件集重写 autoload_files.php。
        $dropped = self::rewriteAutoloadFiles($stage);

        return [$stage, $paths, $dropped];
    }

    /**
     * 按暂存目录里**真实存在**的文件重写 composer 自动加载映射。
     *
     * 排除 dev 依赖树后，原表里指向被排除文件的条目会在 bootstrap 被硬 require
     * 直接致命（实测 myclabs/deep-copy/src/DeepCopy/deep_copy.php）。此处不硬编码
     * 黑名单，而是逐条校验目标文件是否存在——声明⟺实现零漂移落到自动加载器上。
     *
     * 必须**同时**处理三个文件，只改其中一个等于没改（实测踩过）：
     *   1. autoload_static.php —— Composer 2 的**真正来源**。autoload_real.php 直接取
     *      `ComposerStaticInit*::$files` / `::$classMap`，完全不读 autoload_files.php；
     *      只重写 autoload_files.php 时包内 bootstrap 依旧 require 被排除的文件并致命。
     *   2. autoload_files.php  —— 静态文件缺失时的回退来源。
     *   3. autoload_classmap.php —— 同上；classmap_authoritative 时它决定类查找成败。
     * autoload_psr4.php / autoload_namespaces.php 用 __DIR__ 相对路径，目录不存在时
     * 只是查找落空、不会致命，无需重写。
     *
     * 数据源只用两个平铺映射文件（include 即可，不 require autoload_static.php——
     * 后者的类名带哈希，进程内重复 require 会 Cannot redeclare）。
     * 输出路径统一写成 __DIR__ 相对形式：暂存目录是临时的，绝对路径打进包必然失效，
     * 而 __DIR__ 在包内解析为 phar://<alias>/vendor/composer。
     *
     * 副作用可审计：被剔除的条目记入返回值（相对项目根），供报告展示。
     *
     * @return list<string> 被剔除的路径（相对项目根，如 vendor/myclabs/deep-copy/...）
     */
    public static function rewriteAutoloadFiles(string $stage): array
    {
        // 必须 realpath：macOS 的 sys_get_temp_dir() 返回未解析的 /var/folders/...，
        // 而 composer 生成的绝对路径是已解析的 /private/var/folders/...，两者前缀不匹配
        // 会让路径重映射静默失效（实测踩过：产物里出现 ../..../../private/var/... 的畸形路径）。
        $stage = rtrim($stage, '/\\');
        $resolved = realpath($stage);
        if ($resolved !== false) {
            $stage = $resolved;
        }
        $composerDir = $stage . '/vendor/composer';
        if (!is_dir($composerDir)) {
            return [];
        }
        $filesFile = $composerDir . '/autoload_files.php';
        $classmapFile = $composerDir . '/autoload_classmap.php';
        foreach ([$filesFile, $classmapFile] as $required) {
            if (!is_file($required)) {
                throw new \RuntimeException("缺少 composer 映射文件，无法完成打包重写：{$required}");
            }
        }

        // autoload_files.php 里的路径是 composer 生成的绝对路径，先重映射回暂存目录，
        // 否则 is_file() 校验的是项目原件、排除规则完全失效。
        $filesMap = self::remapToStage((array) include $filesFile, $stage);
        $classMap = (array) include $classmapFile;

        [$files, $droppedFiles] = self::keepExisting($filesMap, $stage);
        [$classMapKept, $droppedClasses] = self::keepExisting($classMap, $stage);
        $dropped = array_merge($droppedFiles, $droppedClasses);
        self::writeMapFile($filesFile, 'autoload_files.php', $files);
        self::writeMapFile($classmapFile, 'autoload_classmap.php', $classMapKept);
        self::rewriteStaticBlocks($composerDir . '/autoload_static.php', $files, $classMapKept);

        return array_values(array_unique($dropped));
    }

    /** 把 composer 生成的绝对路径重映射到暂存目录（项目根 → 暂存根）。 */
    private static function remapToStage(array $map, string $stage): array
    {
        $root = self::resolved(static::projectRoot()) . '/';
        $prefix = $stage . '/';
        foreach ($map as $key => $path) {
            if (is_string($path) && str_starts_with($path, $root)) {
                $map[$key] = $prefix . substr($path, strlen($root));
            }
        }

        return $map;
    }

    /** 规范化目录路径（解析符号链接）；解析失败时原样返回。 */
    private static function resolved(string $path): string
    {
        $real = realpath(rtrim($path, '/\\'));
        return $real === false ? rtrim($path, '/\\') : $real;
    }

    /**
     * 只保留目标文件真实存在的条目。
     *
     * @return array{0: array<string,string>, 1: list<string>} [保留项（值已是 PHP 表达式）, 被剔除路径]
     */
    private static function keepExisting(array $map, string $stage): array
    {
        $root = self::resolved(static::projectRoot());
        $kept = [];
        $dropped = [];
        foreach ($map as $key => $path) {
            if (!is_string($path) || !is_file($path)) {
                $dropped[] = self::reportRel($stage, is_string($path) ? $path : var_export($path, true));
                continue;
            }
            $kept[(string) $key] = self::pathExpr($stage, $root, $path);
        }

        return [$kept, $dropped];
    }

    /**
     * 路径 → 包内可解析的 PHP 表达式。
     *
     * vendor 内路径与 composer 自身写法等价：__DIR__ 是 vendor/composer，
     * '../' 指回 vendor/，故渲染为 __DIR__ . '/../vendor/x.php'。
     * 项目根级文件（vendor 之外、仍在包内）以 Phar::running(false) 定位包根。
     * 包外路径（全局安装）原样输出绝对路径——打进包后必然不可用，但这类条目极少，
     * 且静默丢弃比保留一条坏路径更糟（会掩盖真实缺失）。
     */
    private static function pathExpr(string $stage, string $root, string $path): string
    {
        // 必须全是常量表达式：autoload_static.php 把这些值放进类静态属性，
        // 静态属性初始化不允许函数调用（实测踩过
        // 「Constant expression contains invalid operations」），故一律用 __DIR__ 拼接。
        // __DIR__ = phar://<alias>/vendor/composer；拼接片段必须自带前导斜杠，
        // 否则会得到 vendor/composer../symfony 这种粘连路径（实测踩过）。
        // 其中 '/../' 指回 vendor/，'/../../' 指回包根。
        $vendorPrefix = $stage . '/vendor/';
        if (str_starts_with($path, $vendorPrefix)) {
            return "__DIR__ . " . var_export('/../' . substr($path, strlen($vendorPrefix)), true);
        }
        if (str_starts_with($path, $stage . '/')) {
            return "__DIR__ . " . var_export('/../../' . substr($path, strlen($stage)), true);
        }
        if (str_starts_with($path, $root . '/')) {
            return "__DIR__ . " . var_export('/../../' . substr($path, strlen($root)), true);
        }

        // 包外路径（全局安装）：保留绝对路径。打进包后不可用，但静默丢弃更糟。
        return var_export($path, true);
    }

    /** 报告用路径：剥掉暂存目录前缀（暂存目录用完即删，绝对路径无意义）。 */
    private static function reportRel(string $stage, string $path): string
    {
        $prefix = $stage . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** 渲染平铺映射文件（autoload_files.php / autoload_classmap.php）。 */
    private static function writeMapFile(string $file, string $name, array $map): void
    {
        $lines = [
            '<?php',
            '',
            "// {$name} @generated by Composer（打包期按实际文件集重写）",
            '',
            '$vendorDir = dirname(__DIR__);',
            '$baseDir = dirname($vendorDir);',
            '',
            'return array(',
        ];
        foreach ($map as $key => $expr) {
            $lines[] = '    ' . var_export($key, true) . ' => ' . $expr . ',';
        }
        $lines[] = ');';
        $lines[] = '';

        if (@file_put_contents($file, implode("\n", $lines)) === false) {
            throw new \RuntimeException("重写失败：{$file}");
        }
    }

    /** 只替换 autoload_static.php 的 $files / $classMap 两个块，其余块原样保留。 */
    private static function rewriteStaticBlocks(string $staticFile, array $files, array $classMap): void
    {
        if (!is_file($staticFile)) {
            // 无静态映射时 autoload_real.php 回退到平铺映射文件，上面已重写。
            return;
        }
        $src = (string) @file_get_contents($staticFile);
        if ($src === false) {
            throw new \RuntimeException("无法读取：{$staticFile}");
        }
        $lines = self::replacePropBlock(
            explode("\n", $src),
            '$files',
            "    public static \$files = array (\n" . self::rows($files, '        ') . "\n    );"
        );
        $lines = self::replacePropBlock(
            $lines,
            '$classMap',
            "    public static \$classMap = array (\n" . self::rows($classMap, '        ') . "\n    );"
        );

        if (@file_put_contents($staticFile, implode("\n", $lines)) === false) {
            throw new \RuntimeException("重写失败：{$staticFile}");
        }
    }

    /** 渲染 static 块内的条目行（值为已就绪的 PHP 表达式，不能再 var_export）。 */
    private static function rows(array $map, string $indent): string
    {
        $lines = [];
        foreach ($map as $key => $expr) {
            $lines[] = $indent . var_export((string) $key, true) . ' => ' . $expr . ',';
        }

        return implode("\n", $lines);
    }

    /**
     * 替换 `public static $prop = array (...);` 块（行级扫描）。
     *
     * 刻意不用正则：$classMap 块约 4900 行 / 400KB，非贪婪正则在这个规模上直接
     * 「Recursion limit exhausted」（实测踩过）；贪婪正则又会越过第一个 `);`
     * 吞掉后面的 $classMap 块。composer 生成的属性块格式恒定为
     * 「4 空格缩进声明行 → 逐行条目 → 4 空格缩进 ); 行」，按行定位是线性且确定的。
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function replacePropBlock(array $lines, string $prop, string $replacement): array
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (ltrim($line) === 'public static ' . $prop . ' = array (') {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            throw new \RuntimeException("autoload_static.php 未找到 `public static {$prop} = array (` 声明行");
        }
        $end = null;
        foreach ($lines as $j => $line) {
            if ($j <= $start) {
                continue;
            }
            if ($line === '    );') {
                $end = $j;
                break;
            }
        }
        if ($end === null) {
            throw new \RuntimeException("autoload_static.php 的 `public static {$prop}` 块未闭合（缺 `    );`）");
        }

        return array_merge(
            array_slice($lines, 0, $start),
            explode("\n", $replacement),
            array_slice($lines, $end + 1),
        );
    }

    /**
     * 构建 PHAR。完整流水线：precheck → 抬高内存 → 暂存 → 构建 → 校验 → 清理。
     *
     * @return array<string, mixed> 产物清单
     */
    public static function pack(?array $config = null): array
    {
        if ($config !== null) {
            self::override($config);
        }
        $cfg = static::config();
        $issues = self::precheck();
        if ($issues !== []) {
            return ['ok' => false, 'errors' => $issues, 'artifact' => null];
        }

        $limit = ((int) $cfg['memory_limit_mb']) * 1024 * 1024;
        $current = self::bytesOf(ini_get('memory_limit'));
        if ($current !== null && $current < $limit) {
            // memory_limit 是 PHP_INI_ALL，可运行期抬高；失败不阻断（仅报告）。
            @ini_set('memory_limit', (string) $limit);
        }

        $alias = (string) $cfg['phar_filename'];
        $buildDir = self::buildDir();
        if (!is_dir($buildDir) && !@mkdir($buildDir, 0755, true)) {
            return ['ok' => false, 'errors' => ["无法创建产物目录：{$buildDir}"], 'artifact' => null];
        }
        $artifact = $buildDir . '/' . $alias;

        [$stage, $paths, $dropped] = self::stage();
        try {
            @unlink($artifact);
            $phar = new \Phar($artifact, 0, $alias);   // alias 必须等于 basename（约束 2）
            $phar->setSignatureAlgorithm((int) $cfg['signature_algorithm']);
            $phar->buildFromDirectory($stage);          // 约束 4：不用逐文件 addFile
            $phar->setStub(self::renderedStub($alias));
            $phar->stopBuffering();
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ["PHAR 构建失败：" . $e->getMessage()], 'artifact' => null];
        } finally {
            self::rrmdir($stage);
        }

        // 必备入口链校验。两个坑必须绕开：
        //   - is_file() 对 phar URL 的裸形式（kode.phar/kode）恒 false，须用 phar://；
        //   - phar://<alias>/… 只按 cwd 解析裸文件名，构建期 cwd 未必是产物目录，
        //     故必须拼绝对路径（实测：phar://kode.phar/kode=false，phar:///abs/…/kode.phar/kode=true）。
        $absolute = realpath($artifact);
        $absolute = $absolute !== false ? $absolute : $artifact;
        $missing = [];
        foreach ((array) $cfg['must_include'] as $need) {
            if (!file_exists('phar://' . $absolute . '/' . $need)) {
                $missing[] = $need;
            }
        }
        if ($missing !== []) {
            return ['ok' => false, 'errors' => ['产物缺必备入口链：' . implode(', ', $missing)], 'artifact' => $artifact];
        }

        return [
            'ok'            => true,
            'errors'        => [],
            'artifact'      => $artifact,
            'alias'         => $alias,
            'bytes'         => filesize($artifact),
            'sha256'        => hash_file('sha256', $artifact),
            'files'         => count($paths),
            'signature'     => (int) $cfg['signature_algorithm'],
            'dropped_files' => $dropped,
            'memory_limit'  => ini_get('memory_limit'),
        ];
    }

    /**
     * 阶段二：phar → 独立二进制（micro.sfx + phar 拼接）。
     *
     * @param string      $phar 阶段一产物
     * @param string|null $sfx  直接指定 micro.sfx 文件（离线/测试用，跳过下载）
     * @param string|null $phpVersion 覆盖配置里的 php_version
     * @return array<string, mixed>
     */
    public static function toBin(string $phar, ?string $sfx = null, ?string $phpVersion = null): array
    {
        $cfg = static::config();
        if (!is_file($phar)) {
            return ['ok' => false, 'errors' => ["PHAR 不存在：{$phar}"], 'artifact' => null];
        }
        $issues = self::binaryPlatformIssues();
        if ($issues !== [] && $sfx === null) {
            return ['ok' => false, 'errors' => $issues, 'artifact' => null];
        }

        $version = $phpVersion ?? (string) $cfg['php_version'];
        $binFilename = (string) $cfg['bin_filename'];
        $buildDir = self::buildDir();

        if ($sfx === null) {
            $sfx = self::downloadSfx($version);
            if ($sfx === null) {
                return ['ok' => false, 'errors' => ["micro.sfx 下载失败（php{$version}）"], 'artifact' => null];
            }
        } elseif (!is_file($sfx)) {
            return ['ok' => false, 'errors' => ["指定的 micro.sfx 不存在：{$sfx}"], 'artifact' => null];
        }

        $header = self::iniHeader($cfg['custom_ini']);
        $bin = $buildDir . '/' . $binFilename;
        if (!is_dir($buildDir) && !@mkdir($buildDir, 0755, true)) {
            return ['ok' => false, 'errors' => ["无法创建产物目录：{$buildDir}"], 'artifact' => null];
        }
        @unlink($bin);

        $fh = @fopen($bin, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'errors' => ["无法写入：{$bin}"], 'artifact' => null];
        }
        $sfxBytes = @file_get_contents($sfx);
        $pharBytes = @file_get_contents($phar);
        if ($sfxBytes === false || $pharBytes === false) {
            fclose($fh);
            return ['ok' => false, 'errors' => ['读取输入文件失败'], 'artifact' => null];
        }
        $written = ($header !== '' ? fwrite($fh, $header) : 0);
        $written += fwrite($fh, $sfxBytes);
        $written += fwrite($fh, $pharBytes);
        fclose($fh);
        if ($written <= 0) {
            return ['ok' => false, 'errors' => ['写入二进制失败'], 'artifact' => null];
        }

        return [
            'ok'         => true,
            'errors'     => [],
            'artifact'   => $bin,
            'bytes'      => filesize($bin),
            'sha256'     => hash_file('sha256', $bin),
            'phar_bytes' => strlen($pharBytes),
            'sfx_bytes'  => strlen($sfxBytes),
            'ini_bytes'  => strlen($header),
            'php'        => $version,
        ];
    }

    /**
     * custom-ini 头：magic + 4 字节网络序长度 + 正文（workman micro.sfx 约定）。
     */
    public static function iniHeader(mixed $ini): string
    {
        if (!is_string($ini) || trim($ini) === '') {
            return '';
        }
        $body = str_replace("\r\n", "\n", $ini);
        return self::BIN_INI_MAGIC . pack('N', strlen($body)) . $body;
    }

    /**
     * 下载 micro.sfx（workerman 官方分发）。失败返回 null，不抛异常。
     */
    public static function downloadSfx(string $phpVersion): ?string
    {
        $cfg = static::config();
        $base = rtrim((string) $cfg['micro_sfx_base'], '/');
        $dir = sys_get_temp_dir() . '/kode_sfx';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return null;
        }
        $zipName = "php{$phpVersion}.micro.sfx.zip";
        $zip = $dir . '/' . $zipName;
        if (!is_file($zip)) {
            $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'kode-packer']]);
            $src = $base . '/' . $zipName;
            if (!@copy($src, $zip)) {
                @copy($base . '/php' . $phpVersion . '.micro.sfx', $zip);
                if (!is_file($zip)) {
                    return null;
                }
            }
        }
        $target = $dir . "/php{$phpVersion}.micro.sfx";
        if (is_file($target)) {
            return $target;
        }
        if (substr($zip, -4) !== '.zip' || !is_file($zip)) {
            // 直接就是 sfx
            @rename($zip, $target);
            return is_file($target) ? $target : null;
        }
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            return null;
        }
        $name = null;
        for ($i = 0; $i < $z->numFiles; $i++) {
            $entry = (string) $z->getNameIndex($i);
            if (str_ends_with($entry, '.sfx')) {
                $name = $entry;
                break;
            }
        }
        if ($name === null) {
            $z->close();
            return null;
        }
        if ($z->extractTo($dir, $name) === false) {
            $z->close();
            return null;
        }
        $z->close();
        $extracted = $dir . '/' . $name;
        if (!is_file($extracted)) {
            return null;
        }
        @rename($extracted, $target);

        return $target;
    }

    /**
     * 校验既有产物（pack:verify）。
     *
     * 按 basename 分档：等于配置的 bin_filename 视为二进制档（phar 容器语义不适用，
     * 只校验体积/哈希/魔数）；其余一律按 PHAR 校验，签名与入口链缺一不可。
     *
     * @return array{ok: bool, errors: list<string>, checks: array<string, bool|string>}
     */
    public static function verify(string $artifact): array
    {
        $errors = [];
        $checks = [];

        if (!is_file($artifact)) {
            return ['ok' => false, 'errors' => ["文件不存在：{$artifact}"], 'checks' => []];
        }
        $checks['exists'] = true;
        $checks['bytes'] = (string) filesize($artifact);
        $checks['sha256'] = hash_file('sha256', $artifact);

        $alias = basename($artifact);

        if ($alias === (string) static::config()['bin_filename']) {
            // 二进制档：magic(ini) 或 ELF 头二者居其一，否则无法确认是可执行体。
            $head = (string) @file_get_contents($artifact, false, null, 0, 8);
            $checks['tier'] = 'binary';
            $checks['has_ini_header'] = str_starts_with($head, self::BIN_INI_MAGIC);
            $valid = $checks['has_ini_header'] || str_starts_with($head, "\x7fELF");
            $checks['executable_head'] = $valid;
            if (!$valid) {
                $errors[] = '既无 custom-ini 魔数（' . self::BIN_INI_MAGIC . '）也无 ELF 头——'
                    . '无法确认是可执行二进制';
            }

            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => $checks];
        }

        $checks['tier'] = 'phar';
        $absolute = realpath($artifact);
        $absolute = $absolute !== false ? $absolute : $artifact;
        $isPhar = false;
        try {
            $phar = new \Phar($absolute);
            // 签名校验：PHP 8.0+ getSignature() 返回 array（hash/hash_type），
            // 更早版本返回 PharSignature 对象；两种都兼容，不做单一假设。
            $sig = $phar->getSignature();
            if (is_array($sig)) {
                $checks['signature_type'] = (string) ($sig['hash_type'] ?? $sig['type'] ?? 'unknown');
                $checks['signature_hash'] = substr((string) ($sig['hash'] ?? ''), 0, 16) . '…';
            } elseif (is_object($sig) && method_exists($sig, 'getType')) {
                $checks['signature_type'] = (string) $sig->getType();
            } else {
                $checks['signature_type'] = is_object($sig) ? get_class($sig) : get_debug_type($sig);
            }
            // 签名真伪无需单独校验方法（Phar 无 isValid）：new \Phar() 与遍历本身
            // 会校验签名，篡改即抛 UnexpectedValueException，被下方 catch 拦下。
            $checks['files'] = (string) $phar->count();
            $checks['internal_alias'] = (string) $phar->getAlias();
            // 注意：不要用 foreach($phar) 计数——本 PHP 构建的 Phar 迭代器只产出
            // 顶层条目（实测 13 项），会严重低估；count() 才是全量（实测 4972）。
            $stub = (string) $phar->getStub();
            $haltAt = strpos($stub, '__HALT_COMPILER');
            $isPhar = true;      // 全部检查通过才算有效，避免半通过继续往下走
        } catch (\Throwable $e) {
            $errors[] = '不是有效 PHAR：' . $e->getMessage();
        }

        if (!$isPhar) {
            return ['ok' => $errors === [], 'errors' => $errors, 'checks' => $checks];
        }

        $checks['alias'] = $alias;
        $checks['has_halt_compiler'] = $haltAt !== false;
        if (!$checks['has_halt_compiler']) {
            $errors[] = '桩缺 __HALT_COMPILER()——产物可被继续执行任意后续代码';
        } else {
            $checks['stub_bytes'] = (string) $haltAt;
        }
        $checks['stub_maps_alias'] = str_contains($stub, "Phar::mapPhar('{$alias}')")
            || str_contains($stub, "Phar::mapPhar(\"{$alias}\")");
        if (!$checks['stub_maps_alias']) {
            $errors[] = "桩未 mapPhar 到 basename「{$alias}」——运行期会报 implicit alias 冲突";
        }

        // 注意：is_file() 对 phar URL 的裸形式（kode.phar/kode）返回 false——
        // phar 流的 url_stat 只认显式 phar:// 前缀。这里必须用 phar:// + 绝对路径。
        foreach ((array) static::config()['must_include'] as $need) {
            $ok = file_exists('phar://' . $absolute . '/' . $need);
            $checks["has:{$need}"] = $ok;
            if (!$ok) {
                $errors[] = "产物缺必备入口：{$need}";
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => $checks];
    }

    /**
     * 渲染产物报告（人类可读）。
     *
     * @param array<string, mixed> $result pack()/toBin() 返回
     */
    public static function report(array $result): string
    {
        if (empty($result['ok'])) {
            $lines = ['打包失败：'];
            foreach ((array) ($result['errors'] ?? []) as $e) {
                $lines[] = '  - ' . $e;
            }
            return implode("\n", $lines);
        }
        $lines = [];
        $lines[] = '✅ 打包成功';
        $lines[] = '  产物      : ' . $result['artifact'];
        $lines[] = '  体积      : ' . self::humanBytes((int) $result['bytes']);
        $lines[] = '  SHA256    : ' . $result['sha256'];
        if (isset($result['files']) && $result['files'] !== null) {
            $lines[] = '  包内文件  : ' . $result['files'];
        }
        if (isset($result['signature'])) {
            $lines[] = '  签名算法  : ' . (int) $result['signature'];
        }
        if (isset($result['phar_bytes'])) {
            $lines[] = '  构成      : phar ' . self::humanBytes((int) $result['phar_bytes'])
                . ' + micro.sfx ' . self::humanBytes((int) $result['sfx_bytes'])
                . ($result['ini_bytes'] > 0 ? ' + ini ' . (int) $result['ini_bytes'] . 'B' : '');
            $lines[] = '  PHP 版本  : ' . $result['php'];
        }
        return implode("\n", $lines);
    }

    /** 运行时内存上限转字节；'-' 表示无限制返回 null。 */
    private static function bytesOf(string $limit): ?int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-') {
            return null;
        }
        $value = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return round($v, 2) . ' ' . $units[$i];
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $ri = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($ri, \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $path = $f->getPathname();
            if ($f->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
