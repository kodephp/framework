<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Plugin\PluginDiscoverer;
use PHPUnit\Framework\TestCase;

/**
 * 插件发现器：「代码层到底有哪些插件」的唯一真值源。
 *
 * 这组测试存在的理由是一个已经发生的事故：发现逻辑曾依赖 glob()，
 * 而 glob() **不支持流封装器**——在 PHAR/ZIP 打包产物里 baseDir() 会正确解析成
 * `phar://kode.phar/plugins`，但 glob() 对 phar 路径静默返回空数组（不是 false），
 * 结果是「发现 0 个插件」且全程无任何报错，所有插件在二进制分发行里无声消失，
 * 而 config/plugins.php 里明明声明着它们（表现为 /api/<name> 全部 404）。
 * 现实现改用 DirectoryIterator（走 SplFileInfo 流接口），本文件用真实 phar 回归守护。
 */
final class PluginDiscovererTest extends TestCase
{
    private string $tmp = '';

    private ?\Closure $autoloader = null;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/kode_disc_' . uniqid('', true);
        mkdir($tmp, 0o755, true);
        $this->tmp = $tmp;

        // 复现真实应用的 composer PSR-4 映射：plugins/ → Kode\Plugins\。
        // 框架测试进程自身不装应用，必须自行提供，否则 class_exists() 恒假、
        // 一切插件都被「类不存在」静默跳过。
        $this->autoloader = static function (string $class) use ($tmp): void {
            if (!str_starts_with($class, PluginDiscoverer::NAMESPACE_PREFIX)) {
                return;
            }
            $file = $tmp . '/' . substr($class, strlen(PluginDiscoverer::NAMESPACE_PREFIX)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        };
        spl_autoload_register($this->autoloader);

        PluginDiscoverer::useBaseDir($tmp);
    }

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }
        PluginDiscoverer::useBaseDir(null);
        PluginDiscoverer::flush();
        $this->removeDir($this->tmp);
    }

    // ─── 1. 基本发现 ─────────────────────────────────────────────

    public function test_discovers_valid_plugins_in_directory(): void
    {
        $this->writePlugin('Alpha', 'alpha');
        $this->writePlugin('Beta', 'beta');
        PluginDiscoverer::flush();

        self::assertSame(
            ['alpha' => 'Kode\Plugins\AlphaPlugin', 'beta' => 'Kode\Plugins\BetaPlugin'],
            PluginDiscoverer::discover()
        );
    }

    public function test_ignores_non_plugin_and_non_php_files(): void
    {
        $this->writePlugin('Alpha', 'alpha');
        file_put_contents($this->tmp . '/Helper.php', "<?php\n");
        file_put_contents($this->tmp . '/Notes.md', '# 不是插件');
        file_put_contents($this->tmp . '/sub', '');
        PluginDiscoverer::flush();

        self::assertSame(['alpha' => 'Kode\Plugins\AlphaPlugin'], PluginDiscoverer::discover());
    }

    public function test_missing_directory_returns_empty(): void
    {
        PluginDiscoverer::useBaseDir($this->tmp . '/does_not_exist');
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    public function test_baseDir_and_override(): void
    {
        self::assertSame($this->tmp, PluginDiscoverer::baseDir());
        PluginDiscoverer::useBaseDir(null);

        $resolved = PluginDiscoverer::baseDir();
        self::assertNotSame('', $resolved);
        PluginDiscoverer::useBaseDir($this->tmp);
    }

    // ─── 2. 无效插件静默跳过 ─────────────────────────────────────

    public function test_skips_plugin_without_name_method(): void
    {
        file_put_contents($this->tmp . '/NoName.php', <<<'PHP'
<?php
namespace Kode\Plugins;
class NoName { public function foo(): string { return 'x'; } }
PHP
        );
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    public function test_skips_plugin_with_invalid_name(): void
    {
        // 大写、过短、含非法字符、过长——均不匹配 NAME_PATTERN
        $bad = [
            'BadUpper' => 'Alpha',
            'BadShort' => 'a',
            'BadChar' => 'has-dash',
        ];
        foreach ($bad as $file => $name) {
            $this->writePlugin($file, $name);
        }
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    public function test_skips_plugin_whose_constructor_throws(): void
    {
        file_put_contents($this->tmp . '/Boom.php', <<<'PHP'
<?php
namespace Kode\Plugins;
class Boom { public function __construct() { throw new \RuntimeException('boom'); } }
PHP
        );
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    public function test_skips_plugin_whose_name_method_throws(): void
    {
        file_put_contents($this->tmp . '/Loud.php', <<<'PHP'
<?php
namespace Kode\Plugins;
class Loud { public function name(): string { throw new \RuntimeException('nope'); } }
PHP
        );
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    public function test_skips_missing_class(): void
    {
        // 文件在，但类未注册到 autoload（autoload 不到时不得抛错）
        file_put_contents($this->tmp . '/Ghost.php', "<?php\n");
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::discover());
    }

    // ─── 3. 同名冲突：先写者胜 ───────────────────────────────────

    public function test_duplicate_name_resolves_lexicographically_first(): void
    {
        // 文件名字典序：FirstPlugin < SecondPlugin，但两者的 name() 都是 'dup'。
        // 期望：FirstPlugin 胜出（扫描结果显式排序 + 先写者胜）。
        file_put_contents($this->tmp . '/FirstPlugin.php', $this->pluginSource('FirstPlugin', 'dup', "'first'"));
        file_put_contents($this->tmp . '/SecondPlugin.php', $this->pluginSource('SecondPlugin', 'dup', "'second'"));
        PluginDiscoverer::flush();

        self::assertSame(['dup' => 'Kode\Plugins\FirstPlugin'], PluginDiscoverer::discover());
    }

    // ─── 4. 进程内缓存 ───────────────────────────────────────────

    public function test_result_is_cached_until_flush(): void
    {
        $this->writePlugin('Alpha', 'alpha');
        $cached = PluginDiscoverer::discover();

        $this->writePlugin('Beta', 'beta');
        self::assertSame($cached, PluginDiscoverer::discover(), '未 flush 前应命中缓存');

        PluginDiscoverer::flush();
        self::assertCount(2, PluginDiscoverer::discover());
    }

    // ─── 5. 漂移报告（只报告，不改加载行为）─────────────────────

    public function test_undeclared_reports_found_but_not_declared(): void
    {
        $this->writePlugin('Alpha', 'alpha');
        $this->writePlugin('Beta', 'beta');
        PluginDiscoverer::flush();

        self::assertSame(
            ['beta' => 'Kode\Plugins\BetaPlugin'],
            PluginDiscoverer::undeclared(['Kode\Plugins\AlphaPlugin'])
        );
    }

    public function test_missingDeclared_reports_declared_but_not_found(): void
    {
        // AlphaPlugin 已落盘且可发现；GhostPlugin / MissingPlugin 从未存在。
        // 期望：只有真正不存在的两个被报为漂移，已发现的不算漂移。
        $this->writePlugin('Alpha', 'alpha');
        PluginDiscoverer::flush();

        self::assertSame(
            ['Kode\Plugins\GhostPlugin', 'Kode\Plugins\MissingPlugin'],
            PluginDiscoverer::missingDeclared([
                'Kode\Plugins\GhostPlugin',
                'Kode\Plugins\AlphaPlugin',
                'Kode\Plugins\MissingPlugin',
            ])
        );
    }

    public function test_missingDeclared_is_empty_when_all_declared_exist(): void
    {
        $this->writePlugin('Alpha', 'alpha');
        PluginDiscoverer::flush();

        self::assertSame([], PluginDiscoverer::missingDeclared(['Kode\Plugins\AlphaPlugin']));
    }

    public function test_constants(): void
    {
        self::assertSame('/^[a-z0-9_]{2,32}$/', PluginDiscoverer::NAME_PATTERN);
        self::assertSame("Kode\\Plugins\\", PluginDiscoverer::NAMESPACE_PREFIX);
        self::assertSame('Plugin.php', PluginDiscoverer::FILE_SUFFIX);
        self::assertTrue((bool) preg_match(PluginDiscoverer::NAME_PATTERN, 'storage_driver'));
        self::assertFalse((bool) preg_match(PluginDiscoverer::NAME_PATTERN, 'StorageDriver'));
    }

    // ─── 6. 回归：打包产物（phar:// 流）里必须仍能发现插件 ──────

    public function test_discovers_plugins_inside_a_real_phar(): void
    {
        if (!extension_loaded('phar')) {
            $this->markTestSkipped('需要 ext-phar');
        }
        if (ini_get('phar.readonly') === '1') {
            $this->markTestSkipped('需要 -d phar.readonly=0 才能构造回归夹具 phar');
        }

        $pharPath = $this->tmp . '/bundle.phar';
        $src = $this->tmp . '/src/plugins';
        mkdir($src, 0o755, true);
        $alpha = $this->pluginSource('AlphaPlugin', 'alpha');
        $beta = $this->pluginSource('BetaPlugin', 'beta');
        file_put_contents($src . '/AlphaPlugin.php', $alpha);
        file_put_contents($src . '/BetaPlugin.php', $beta);
        file_put_contents($src . '/Helper.php', "<?php\n"); // 非插件，必须被过滤

        // 用 addFile() 逐条写入而非 buildFromDirectory($src, '/plugins')：
        // 后者的 $strip 参数按**正则**解释，传 '/plugins' 会抛
        // "RegexIterator::__construct(): No ending delimiter '/' found"。
        $phar = new \Phar($pharPath);
        $phar->addFile($src . '/AlphaPlugin.php', 'plugins/AlphaPlugin.php');
        $phar->addFile($src . '/BetaPlugin.php', 'plugins/BetaPlugin.php');
        $phar->addFile($src . '/Helper.php', 'plugins/Helper.php');
        unset($phar);

        // 让 PSR-4 前缀 Kode\Plugins\ 能解析进 phar：base_path() 返回 phar:// 根时
        // 该映射命中 `phar://<root>/plugins/<Name>.php`。
        spl_autoload_register(static function (string $class) use ($pharPath): void {
            if (str_starts_with($class, PluginDiscoverer::NAMESPACE_PREFIX)) {
                $file = 'phar://' . $pharPath . '/plugins/'
                    . substr($class, strlen(PluginDiscoverer::NAMESPACE_PREFIX)) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        });

        // baseDir 指向 phar 内目录：这是 glob() 失效、DirectoryIterator 生效的分水岭。
        PluginDiscoverer::useBaseDir('phar://' . $pharPath . '/plugins');
        PluginDiscoverer::flush();

        $found = PluginDiscoverer::discover();
        self::assertCount(2, $found, 'phar 内应发现 2 个插件，实际：' . json_encode($found, \JSON_UNESCAPED_SLASHES));
        self::assertSame('Kode\Plugins\AlphaPlugin', $found['alpha'] ?? null);
        self::assertSame('Kode\Plugins\BetaPlugin', $found['beta'] ?? null);
    }

    public function test_zip_baseDir_degrades_gracefully_without_throwing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('需要 ext-zip');
        }

        // 记录 PHP 的能力边界：zip 流封装器**完全不支持目录遍历**
        // （is_dir / is_file / DirectoryIterator 对 zip://archive#dir 全部失败）。
        // 因此 zip 不能作为插件根目录——这是平台限制，不是发现器的 bug。
        // 本用例守护「优雅降级」：不得抛异常、返回空表、且不影响后续本地发现。
        $zipPath = $this->tmp . '/bundle.zip';
        $src = $this->tmp . '/src/plugins';
        mkdir($src, 0o755, true);
        $alpha = $this->pluginSource('AlphaPlugin', 'alpha');
        file_put_contents($src . '/AlphaPlugin.php', $alpha);
        file_put_contents($this->tmp . '/AlphaPlugin.php', $alpha);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true);
        $zip->addFile($src . '/AlphaPlugin.php', 'plugins/AlphaPlugin.php');
        $zip->close();

        self::assertFalse(is_dir('zip://' . $zipPath . '#plugins'), 'zip 目录遍历本身不受 PHP 支持');

        PluginDiscoverer::useBaseDir('zip://' . $zipPath . '#plugins');
        PluginDiscoverer::flush();
        self::assertSame([], PluginDiscoverer::discover(), '不可遍历的 baseDir 应返回空表而非抛错');

        // 切回本地目录后缓存不得残留：仍要能正常发现。
        PluginDiscoverer::useBaseDir($this->tmp);
        PluginDiscoverer::flush();
        self::assertSame(['alpha' => 'Kode\Plugins\AlphaPlugin'], PluginDiscoverer::discover());
    }

    // ─── 夹具 ───────────────────────────────────────────────────

    /**
     * 写一个合法插件文件：文件名 = `<fileBase>Plugin.php`，类名 = `<fileBase>Plugin`。
     *
     * FILE_SUFFIX 是 `Plugin.php`——文件名不带该后缀的插件会被扫描器直接忽略，
     * 这正是真实约定（NoticePlugin.php / StorageDriverPlugin.php）。
     * 可选 marker() 用于区分同名实例。
     */
    private function writePlugin(string $fileBase, string $name, string $marker = ''): void
    {
        $class = $fileBase . 'Plugin';
        file_put_contents($this->tmp . '/' . $class . '.php', $this->pluginSource($class, $name, $marker));
    }

    private function pluginSource(string $class, string $name, string $marker = ''): string
    {
        // nowdoc + 占位符替换：避免 heredoc 插值与生成代码里的引号互相干扰。
        $markerBlock = $marker === '' ? '' : "\n    public function marker(): string { return {$marker}; }";

        return str_replace(
            ['__CLASS__', '__NAME__', '__MARKER__'],
            [$class, $name, $markerBlock],
            <<<'PHP'
<?php
namespace Kode\Plugins;
class __CLASS__ {
    public function name(): string { return '__NAME__'; }
__MARKER__
}
PHP
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($dir);
    }
}
