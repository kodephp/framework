<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\MakePluginCommand;
use PHPUnit\Framework\TestCase;

/**
 * make:plugin 脚手架命令测试。
 *
 * 覆盖：
 *   - 基础生成：类文件 + plugin.json（含命名空间 / 实现 PluginInterface / name() 返回）
 *   - 命名规范化：Blog → BlogPlugin、BlogPlugin → BlogPlugin、blog → BlogPlugin、
 *     my-newsletter → MyNewsletterPlugin、blog_plugin → BlogPlugin
 *   - --force：覆盖已存在文件
 *   - 无 --force 遇已存在：返回 2、不覆盖
 *   - --register：写入 config/plugins.php（空数组 / 已有项 / 幂等）
 *   - --json：结构化输出（CI 消费）
 *   - 校验失败：空名、单字符、含大写字母/连字符的非法名
 *   - config/plugins.php 不存在时 --register 优雅降级（不报错、退出码 0）
 *
 * 与 MakeCommandsTest 分工：本文件只测 make:plugin，其他 make:* 由 MakeCommandsTest 覆盖。
 */
final class MakePluginCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kode_make_plugin_' . uniqid('', true);
        mkdir($this->tmp, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function execute(array $argv): int
    {
        $cmd = new MakePluginCommand($this->tmp);
        return $cmd->fire($this->inputFor($cmd, $argv), new Output(fopen('php://memory', 'w')));
    }

    private function inputFor(Command $cmd, array $argv): Input
    {
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(AsCommand::class);
        $name = 'cmd';
        $usage = '';
        if ($attrs !== []) {
            $inst = $attrs[0]->newInstance();
            $name = $inst->name;
            $usage = $inst->usage;
        }

        return new Input(array_merge([$name], $argv), $usage !== '' ? new Signature($usage) : null);
    }

    private function assertGenerated(string $path, string $needle): void
    {
        $full = $this->tmp . '/' . ltrim($path, '/');
        self::assertFileExists($full, "期望生成文件：{$path}");
        $body = (string) file_get_contents($full);
        self::assertStringContainsString($needle, $body, "文件 {$path} 缺少内容：{$needle}");
    }

    private function lint(string $path): void
    {
        $full = $this->tmp . '/' . ltrim($path, '/');
        $result = shell_exec('php -l ' . escapeshellarg($full) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $result, "语法错误：{$path}");
    }

    private function writePluginsConfig(string $content): void
    {
        $this->ensureDir('config');
        file_put_contents($this->tmp . '/config/plugins.php', $content);
    }

    private function ensureDir(string $dir): void
    {
        $full = $this->tmp . '/' . $dir;
        if (!is_dir($full)) {
            mkdir($full, 0o755, true);
        }
    }

    // ─── 1. 基础生成 ───────────────────────────────────────────────

    public function test_generates_class_and_manifest(): void
    {
        $code = $this->execute(['Blog']);
        self::assertSame(0, $code);

        $this->assertGenerated('plugins/BlogPlugin.php', 'namespace Kode\Plugins;');
        $this->assertGenerated('plugins/BlogPlugin.php', 'implements PluginInterface');
        $this->assertGenerated('plugins/BlogPlugin.php', "return 'blog';");
        $this->assertGenerated('plugins/BlogPlugin.php', "final class BlogPlugin");
        $this->assertGenerated('plugins/BlogPlugin.php', 'public function register(PluginManager $manager)');
        $this->assertGenerated('plugins/BlogPlugin.php', "addRoute('blog.hello'");
        $this->lint('plugins/BlogPlugin.php');

        $this->assertGenerated('plugins/BlogPlugin.plugin.json', '"name": "blog"');
        $this->assertGenerated('plugins/BlogPlugin.plugin.json', '"version": "1.0.0"');
        // JSON 里反斜杠须转义为 \\，所以断言也要写 \\
        $this->assertGenerated('plugins/BlogPlugin.plugin.json', '"class": "Kode\\\\Plugins\\\\BlogPlugin"');
    }

    // ─── 2. 命名规范化 ────────────────────────────────────────────

    public function test_lower_input_becomes_studly(): void
    {
        $this->execute(['blog']);
        $this->assertGenerated('plugins/BlogPlugin.php', 'final class BlogPlugin');
        $this->assertGenerated('plugins/BlogPlugin.php', "return 'blog';");
    }

    public function test_keeps_trailing_plugin_suffix(): void
    {
        $this->execute(['BlogPlugin']);
        $this->assertGenerated('plugins/BlogPlugin.php', 'final class BlogPlugin');
        $this->assertGenerated('plugins/BlogPlugin.php', "return 'blog';");
    }

    public function test_kebab_case_becomes_studly(): void
    {
        $this->execute(['my-newsletter']);
        $this->assertGenerated('plugins/MyNewsletterPlugin.php', 'final class MyNewsletterPlugin');
        $this->assertGenerated('plugins/MyNewsletterPlugin.php', "return 'my_newsletter';");
    }

    public function test_snake_case_input_strips_suffix_and_normalizes(): void
    {
        // 输入 "blog_plugin" → stripSuffix 大小写不敏感剥离 "plugin" → "blog" → studly → Blog → 加 Plugin → BlogPlugin
        // pluginName 走 snake("blog") = "blog"
        $this->execute(['blog_plugin']);
        $this->assertGenerated('plugins/BlogPlugin.php', 'final class BlogPlugin');
        $this->assertGenerated('plugins/BlogPlugin.php', "return 'blog';");
    }

    public function test_strips_lower_plugin_suffix(): void
    {
        // "blogplugin" → 剥后缀 "blog" → studly → Blog → 加 Plugin → BlogPlugin
        $this->execute(['blogplugin']);
        $this->assertGenerated('plugins/BlogPlugin.php', 'final class BlogPlugin');
        $this->assertGenerated('plugins/BlogPlugin.php', "return 'blog';");
    }

    // ─── 3. --force ────────────────────────────────────────────────

    public function test_force_overwrites_existing_files(): void
    {
        $this->execute(['Blog']);
        // 手工篡改一次内容
        file_put_contents($this->tmp . '/plugins/BlogPlugin.php', '<?php /* tampered */');
        $this->execute(['Blog', '--force']);

        $body = (string) file_get_contents($this->tmp . '/plugins/BlogPlugin.php');
        self::assertStringContainsString('final class BlogPlugin', $body, '--force 应覆盖已存在文件');
        self::assertStringNotContainsString('tampered', $body);
    }

    public function test_skip_when_exists_without_force_returns_2(): void
    {
        $this->execute(['Blog']);
        $code = $this->execute(['Blog']);
        self::assertSame(2, $code);
    }

    public function test_skip_when_exists_preserves_content(): void
    {
        $this->execute(['Blog']);
        $tampered = '<?php /* user edit */';
        file_put_contents($this->tmp . '/plugins/BlogPlugin.php', $tampered);
        $this->execute(['Blog']); // 无 --force

        $body = (string) file_get_contents($this->tmp . '/plugins/BlogPlugin.php');
        self::assertStringContainsString('user edit', $body, '无 --force 时不覆盖已存在文件');
    }

    // ─── 4. --register ─────────────────────────────────────────────

    public function test_register_into_empty_plugins_array(): void
    {
        $this->writePluginsConfig('<?php return ["plugins" => []];');
        $code = $this->execute(['Blog', '--register']);
        self::assertSame(0, $code);

        $body = (string) file_get_contents($this->tmp . '/config/plugins.php');
        self::assertStringContainsString('\\Kode\\Plugins\\BlogPlugin::class', $body);
        $this->lint('config/plugins.php');
    }

    public function test_register_into_existing_array(): void
    {
        $this->writePluginsConfig(<<<PHP
<?php

return [
    'plugins' => [
        \Kode\Plugins\NoticePlugin::class,
        \Kode\Plugins\StorageDriverPlugin::class,
    ],
];
PHP);
        $code = $this->execute(['Feed', '--register']);
        self::assertSame(0, $code);

        $body = (string) file_get_contents($this->tmp . '/config/plugins.php');
        self::assertStringContainsString('\\Kode\\Plugins\\NoticePlugin::class', $body);
        self::assertStringContainsString('\\Kode\\Plugins\\StorageDriverPlugin::class', $body);
        self::assertStringContainsString('\\Kode\\Plugins\\FeedPlugin::class', $body);
        $this->lint('config/plugins.php');
    }

    public function test_register_is_idempotent(): void
    {
        $this->writePluginsConfig('<?php return ["plugins" => []];');
        $this->execute(['Blog', '--register']);
        $before = (string) file_get_contents($this->tmp . '/config/plugins.php');

        // 第二次：已声明 → 不重复添加
        $code = $this->execute(['Blog', '--register', '--force']);
        self::assertSame(0, $code);
        $after = (string) file_get_contents($this->tmp . '/config/plugins.php');
        self::assertSame($before, $after, '幂等：已声明后再次 --register 不应修改文件');

        $occurrences = substr_count($after, '\\Kode\\Plugins\\BlogPlugin::class');
        self::assertSame(1, $occurrences, '插件类引用应只出现一次');
    }

    public function test_register_without_config_file_degrades_gracefully(): void
    {
        // 无 config/plugins.php：--register 应仅跳过声明，不报错、不影响文件生成
        $code = $this->execute(['Solo', '--register']);
        self::assertSame(0, $code);
        self::assertFileExists($this->tmp . '/plugins/SoloPlugin.php');
        self::assertFileExists($this->tmp . '/plugins/SoloPlugin.plugin.json');
        self::assertFileDoesNotExist($this->tmp . '/config/plugins.php');
    }

    public function test_register_with_double_quoted_key(): void
    {
        $this->writePluginsConfig('<?php return ["plugins" => []];');
        $this->execute(['Tag', '--register']);

        $body = (string) file_get_contents($this->tmp . '/config/plugins.php');
        self::assertStringContainsString('\\Kode\\Plugins\\TagPlugin::class', $body);
        $this->lint('config/plugins.php');
    }

    // ─── 5. --json ────────────────────────────────────────────────

    public function test_json_output_shape(): void
    {
        // 捕获 json 输出：替换 Output 为可读取的内存流
        $cmd = new MakePluginCommand($this->tmp);
        $stream = fopen('php://temp', 'r+');
        $out = new Output($stream);
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(AsCommand::class);
        $inst = $attrs[0]->newInstance();
        $input = new Input(
            ['make:plugin', 'Blog', '--json'],
            new Signature($inst->usage)
        );
        $cmd->fire($input, $out);

        rewind($stream);
        $payload = json_decode((string) stream_get_contents($stream), true);
        fclose($stream);

        self::assertIsArray($payload);
        self::assertTrue($payload['ok']);
        self::assertSame('Kode\\Plugins\\BlogPlugin', $payload['class']);
        self::assertSame('blog', $payload['name']);
        self::assertFalse($payload['registered']);
        self::assertArrayHasKey('class', $payload['files']);
        self::assertArrayHasKey('manifest', $payload['files']);
    }

    public function test_json_reports_registered_when_flag_set(): void
    {
        $this->writePluginsConfig('<?php return ["plugins" => []];');

        $cmd = new MakePluginCommand($this->tmp);
        $stream = fopen('php://temp', 'r+');
        $out = new Output($stream);
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(AsCommand::class);
        $inst = $attrs[0]->newInstance();
        $input = new Input(
            ['make:plugin', 'Blog', '--register', '--json'],
            new Signature($inst->usage)
        );
        $cmd->fire($input, $out);

        rewind($stream);
        $payload = json_decode((string) stream_get_contents($stream), true);
        fclose($stream);

        self::assertTrue($payload['ok']);
        self::assertTrue($payload['registered']);
    }

    public function test_json_reports_exists_error(): void
    {
        $this->execute(['Blog']);

        $cmd = new MakePluginCommand($this->tmp);
        $stream = fopen('php://temp', 'r+');
        $out = new Output($stream);
        $ref = new \ReflectionClass($cmd);
        $attrs = $ref->getAttributes(AsCommand::class);
        $inst = $attrs[0]->newInstance();
        $input = new Input(
            ['make:plugin', 'Blog', '--json'],
            new Signature($inst->usage)
        );
        $code = $cmd->fire($input, $out);

        rewind($stream);
        $payload = json_decode((string) stream_get_contents($stream), true);
        fclose($stream);

        self::assertSame(2, $code);
        self::assertIsArray($payload);
        self::assertFalse($payload['ok']);
        self::assertSame('exists', $payload['reason']);
    }

    // ─── 6. 校验失败 ──────────────────────────────────────────────

    public function test_empty_name_returns_error(): void
    {
        // 空 name 参数 → Signature 层校验失败（参数缺失），命令不进入 handle
        $code = $this->execute([]);
        self::assertNotSame(0, $code);
    }

    public function test_single_char_name_is_invalid(): void
    {
        // NAME_PATTERN 要求 2-32 位
        $code = $this->execute(['a']);
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->tmp . '/plugins/APlugin.php');
    }

    public function test_non_ascii_name_is_rejected(): void
    {
        // 中文字符是 \p{L}，会穿过 studly/snake 不变；但 NAME_PATTERN 只允许 [a-z0-9_]
        // → 最终校验必失败
        $code = $this->execute(['博客']);
        self::assertSame(1, $code);
        self::assertFileDoesNotExist($this->tmp . '/plugins/博客Plugin.php');
    }

    public function test_studly_normalizes_uppercase_but_accepts(): void
    {
        // "BlogPost" → studly → BlogPost → snake → blog_post → 匹配 NAME_PATTERN（合法）
        // 这里验证的是「大写输入会被规范化后接受」，而非拒绝
        $code = $this->execute(['BlogPost']);
        self::assertSame(0, $code);
        $this->assertGenerated('plugins/BlogPostPlugin.php', 'final class BlogPostPlugin');
        $this->assertGenerated('plugins/BlogPostPlugin.php', "return 'blog_post';");
    }

    // ─── 7. plugin.json 合法性 ────────────────────────────────────

    public function test_manifest_is_valid_json(): void
    {
        $this->execute(['Blog']);
        $body = (string) file_get_contents($this->tmp . '/plugins/BlogPlugin.plugin.json');
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'plugin.json 应为合法 JSON');
        self::assertSame('blog', $decoded['name']);
        self::assertSame('Kode\\Plugins\\BlogPlugin', $decoded['class']);
        self::assertSame('MIT', $decoded['license']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
