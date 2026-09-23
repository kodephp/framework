<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;
use Kode\Framework\Plugin\PluginDiscoverer;

/**
 * 生成插件骨架。
 *
 *   kode make:plugin Blog
 *   kode make:plugin BlogPlugin --force --register
 *
 * 用法：
 *   make:plugin <Name>                    生成 plugins/<Name>Plugin.php
 *   make:plugin <Name> --force            覆盖已存在的文件
 *   make:plugin <Name> --register         同时写入 config/plugins.php
 *   make:plugin <Name> --json             JSON 输出（CI 消费）
 *
 * 生成内容：
 *   1. plugins/<Name>Plugin.php — 实现 PluginInterface 的骨架类
 *   2. plugins/<Name>Plugin.plugin.json — 插件清单（name/version/author/description）
 *
 * 命名规则：
 *   - 输入 "Blog" → 文件名 "BlogPlugin.php"，类名 "Kode\Plugins\BlogPlugin"
 *   - 输入 "blog" → 文件名 "BlogPlugin.php"（自动转 StudlyCase）
 *   - 输入 "BlogPlugin" → 文件名 "BlogPlugin.php"（保留）
 *   - 输入 "blog-plugin" → 文件名 "BlogPlugin.php"（连字符转驼峰）
 *
 * 退出码：0 = 成功；1 = 参数错误或写入失败；2 = 文件已存在（未加 --force）。
 *
 * 只读原则：除指定的插件文件与 config/plugins.php 外不修改任何文件。
 */
#[AsCommand(
    name: 'make:plugin',
    description: '生成插件骨架（plugins/<Name>Plugin.php + plugin.json）',
    usage: 'make:plugin {name} {--force} {--register:bool} {--json:bool}',
)]
final class MakePluginCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['force', 'register', 'json'];

    use GeneratesFiles;

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        $raw = (string) $this->arg('name', '');
        if ($raw === '') {
            $this->error('请提供插件名：make:plugin Blog');
            return 1;
        }

        // 规范化：去掉可能的 Plugin 后缀，转 StudlyCase，再加 Plugin
        $base = $this->stripSuffix($raw, 'Plugin');
        $class = $this->studly($base);
        if (!str_ends_with($class, 'Plugin')) {
            $class .= 'Plugin';
        }

        // 校验插件名格式（与 PluginDiscoverer::NAME_PATTERN 一致）
        $pluginName = $this->snake($base);
        if (!preg_match(PluginDiscoverer::NAME_PATTERN, $pluginName)) {
            $this->error(
                "插件名 '{$pluginName}' 不合法：须匹配 " . PluginDiscoverer::NAME_PATTERN
                . '（小写字母/数字/下划线，2-32 位）'
            );
            return 1;
        }

        $force = $this->flag('force', false);
        $register = $this->flag('register', false);
        $json = $this->flag('json', false);

        // 生成插件类
        $classContent = $this->buildClassContent($class, $pluginName);
        $classPath = $this->path('plugins/' . $class . '.php');
        $classWritten = $this->writeFile($classPath, $classContent, $force);

        if (!$classWritten && !$force) {
            if ($json) {
                $this->output->json(['ok' => false, 'reason' => 'exists', 'path' => $classPath]);
            } else {
                $this->warn("已存在，跳过（用 --force 覆盖）：{$classPath}");
            }
            return 2;
        }

        // 生成 plugin.json
        $manifestContent = $this->buildManifestContent($class, $pluginName);
        $manifestPath = $this->path('plugins/' . $class . '.plugin.json');
        $this->writeFile($manifestPath, $manifestContent, $force);

        // 可选：写入 config/plugins.php
        $registered = false;
        if ($register) {
            $registered = $this->registerPlugin($class);
        }

        $result = [
            'ok' => true,
            'class' => "Kode\\Plugins\\{$class}",
            'name' => $pluginName,
            'files' => [
                'class' => $classPath,
                'manifest' => $manifestPath,
            ],
            'registered' => $registered,
        ];

        if ($json) {
            $this->output->json($result);
            return 0;
        }

        $this->success("已生成插件：Kode\\Plugins\\{$class}");
        $this->line("  类文件：{$classPath}");
        $this->line("  清单：{$manifestPath}");
        if ($registered) {
            $this->line("  已写入 config/plugins.php");
        } else {
            $this->line("  声明：在 config/plugins.php 的 plugins 数组里添加：");
            $this->line("    \\Kode\\Plugins\\{$class}::class,");
        }
        $this->line('');
        $this->line('下一步：');
        $this->line('  1. 编辑 ' . $classPath . ' 实现你的插件逻辑');
        $this->line('  2. 运行 php kode plugin:audit 审计契约');
        $this->line('  3. 运行 php kode plugin:deps 查看依赖关系');

        return 0;
    }

    /** 去掉末尾的 Plugin 后缀（大小写不敏感）。 */
    private function stripSuffix(string $value, string $suffix): string
    {
        if (str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }
        if (str_ends_with(strtolower($value), strtolower($suffix))) {
            return substr($value, 0, -strlen($suffix));
        }
        return $value;
    }

    /** 生成插件类内容。 */
    private function buildClassContent(string $class, string $pluginName): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Kode\Plugins;

use Kode\Framework\Plugin\PluginInterface;
use Kode\Framework\Plugin\PluginManager;

/**
 * {$class}（由 make:plugin 生成）
 *
 * 插件名：{$pluginName}
 * 路由前缀：/api/{$pluginName}/*（由 PluginGateMiddleware 按前缀门禁）
 *
 * 生命周期：
 *   - register()：绑定服务、注册路由/监听器/命令（框架启动期调用一次）
 *   - boot()：插件自身初始化（register 之后，路由已就绪）
 *
 * 能力声明（契约审计会校验声明⟺实现零漂移）：
 *   - requires()：依赖的插件名列表
 *   - configSchema()：配置项 schema
 *   - drivers()：提供的驱动名列表
 *   - commands()：控制台命令类列表
 *   - events()：监听的事件列表
 *   - crons()：定时任务声明
 *   - menus()：前端菜单项
 *   - permissions()：权限码声明
 */
final class {$class} implements PluginInterface
{
    public function name(): string
    {
        return '{$pluginName}';
    }

    public function register(PluginManager \$manager): void
    {
        // 注册路由（自动打 plugin:{$pluginName} 来源标签）
        \$manager->addRoute('{$pluginName}.hello', 'GET', '/api/{$pluginName}', static fn(): array => [
            'plugin' => '{$pluginName}',
            'hello' => 'world',
        ]);

        // 绑定服务到容器
        // \$manager->bind('{$pluginName}.service', static fn(): YourService => new YourService());
    }

    public function boot(PluginManager \$manager): void
    {
        // 插件启动逻辑（预热缓存、注册定时任务等）
    }

    // ── 可选能力声明（按需实现，审计会校验）──

    public function requires(): array
    {
        return [];
    }

    public function version(): string
    {
        return '1.0.0';
    }
}

PHP;
    }

    /** 生成 plugin.json 清单内容。 */
    private function buildManifestContent(string $class, string $pluginName): string
    {
        // 注意：JSON 里反斜杠须转义为 \\\\，heredoc 中 \\\\ 渲染为字面 \\（两字符）。
        return <<<JSON
{
    "name": "{$pluginName}",
    "version": "1.0.0",
    "class": "Kode\\\\Plugins\\\\{$class}",
    "description": "{$pluginName} 插件（由 make:plugin 生成）",
    "author": "",
    "license": "MIT"
}
JSON . PHP_EOL;
    }

    /**
     * 将插件类写入 config/plugins.php 的 plugins 数组。
     *
     * 支持两种格式：
     *   - <?php return ['plugins' => [ ... ]];   （单行）
     *   - <?php return ['plugins' => [           （多行）
     *         \Foo\Bar::class,
     *       ]];
     * 用「括号配对」定位闭合 ]，避免嵌套/注释/多行导致的正则漏匹配。
     *
     * @return bool 是否成功写入
     */
    private function registerPlugin(string $class): bool
    {
        $configPath = $this->path('config/plugins.php');
        if (!is_file($configPath)) {
            $this->warn("config/plugins.php 不存在，跳过声明");
            return false;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $this->error("无法读取 config/plugins.php");
            return false;
        }

        // 检查是否已声明
        $classRef = "\\Kode\\Plugins\\{$class}::class";
        if (str_contains($content, $classRef)) {
            $this->comment("{$class} 已在 config/plugins.php 中声明");
            return true;
        }

        // 定位 'plugins' 或 "plugins" 键及其后的开括号
        $keyPattern = '/([\'"]plugins[\'"]\s*=>\s*\[)/';
        if (!preg_match($keyPattern, $content, $m, \PREG_OFFSET_CAPTURE)) {
            $this->error("config/plugins.php 中未找到 plugins 键");
            return false;
        }

        $openBracket = $m[0][1] + strlen($m[0][0]) - 1; // 开括号 [ 的位置
        $closePos = $this->findMatchingBracket($content, $openBracket);
        if ($closePos === -1) {
            $this->error("config/plugins.php 中 plugins 数组括号不匹配");
            return false;
        }

        $inner = substr($content, $openBracket + 1, $closePos - $openBracket - 1);
        $indent = '        ';
        if (trim($inner) === '') {
            $newInner = "\n{$indent}{$classRef},\n    ";
        } else {
            // rtrim 掉原数组尾部的缩进空白，避免追加时产生「带空格的空行」
            $newInner = rtrim($inner, " \t") . "\n{$indent}{$classRef},\n    ";
        }

        $newContent = substr($content, 0, $openBracket + 1)
            . $newInner
            . substr($content, $closePos);

        if (file_put_contents($configPath, $newContent) === false) {
            $this->error("无法写入 config/plugins.php");
            return false;
        }

        return true;
    }

    /**
     * 从开括号位置向前扫描，返回匹配的闭括号位置（支持字符串内括号跳过）。
     */
    private function findMatchingBracket(string $content, int $openPos): int
    {
        $len = strlen($content);
        $depth = 0;
        $inSingle = $inDouble = false;
        $escape = false;
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $content[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\' && ($inSingle || $inDouble)) {
                $escape = true;
                continue;
            }
            if ($inSingle) {
                if ($ch === "'") { $inSingle = false; }
                continue;
            }
            if ($inDouble) {
                if ($ch === '"') { $inDouble = false; }
                continue;
            }
            if ($ch === "'") { $inSingle = true; continue; }
            if ($ch === '"') { $inDouble = true; continue; }
            if ($ch === '[') { $depth++; continue; }
            if ($ch === ']') {
                $depth--;
                if ($depth === 0) { return $i; }
            }
        }
        return -1;
    }
}
