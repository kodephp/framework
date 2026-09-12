<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\PackInfoCommand;
use Kode\Framework\Packaging\Packager;
use PHPUnit\Framework\TestCase;

/**
 * pack:info 命令测试：只读诊断输出。
 *
 * 覆盖：
 *   - 文本模式：各字段被打印（引擎/产物/平台/前置检查/二进制档位/文件统计）
 *   - JSON 模式：顶层键齐全、字段类型正确、与 Packager 静态方法返回值一致
 *   - 退出码恒为 0（只读、不构建）
 *   - 前置检查 pass=false 时仍以 0 退出（命令本身成功）
 *   - signatureName() 数字→文本映射（间接验证 signature_algorithm 显示为 SHA256）
 *
 * 与 PackagerTest 分工：本文件只测命令层的「渲染与输出契约」，
 * Packager 引擎本身的三层分发 / 配置 / 预检查语义由 PackagerTest 覆盖。
 */
final class PackInfoCommandTest extends TestCase
{
    protected function setUp(): void
    {
        // 前置测试可能污染 Packager 静态状态（override/useClass），重置以保证本文件独立可测。
        Packager::useClass(Packager::class);
        Packager::override([]);
    }

    protected function tearDown(): void
    {
        Packager::useClass(Packager::class);
        Packager::override([]);
    }

    private function execute(array $argv): int
    {
        $cmd = new PackInfoCommand();
        return $cmd->fire($this->inputFor($cmd, $argv), new Output(fopen('php://memory', 'w')));
    }

    private function executeAndCapture(array $argv): array
    {
        $cmd = new PackInfoCommand();
        $stream = fopen('php://temp', 'r+');
        $out = new Output($stream);
        $code = $cmd->fire($this->inputFor($cmd, $argv), $out);

        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);

        return ['code' => $code, 'text' => $text];
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

    // ─── 1. 退出码与基本契约 ────────────────────────────────────────

    public function test_text_mode_returns_zero(): void
    {
        $result = $this->executeAndCapture([]);
        self::assertSame(0, $result['code']);
        self::assertNotEmpty($result['text']);
    }

    public function test_json_mode_returns_zero(): void
    {
        $code = $this->execute(['--json']);
        self::assertSame(0, $code);
    }

    public function test_readonly_does_not_throw_even_when_phar_readonly(): void
    {
        // phar.readonly=1 是常规状态，命令应正常输出而非崩溃
        $result = $this->executeAndCapture([]);
        self::assertSame(0, $result['code']);
        self::assertStringContainsString('phar.readonly', $result['text']);
    }

    // ─── 2. 文本模式字段齐全 ──────────────────────────────────────

    public function test_text_output_contains_all_sections(): void
    {
        $result = $this->executeAndCapture([]);
        $text = $result['text'];

        // 六大段标题
        self::assertStringContainsString('打包配置与平台状态', $text);
        self::assertStringContainsString('引擎', $text);
        self::assertStringContainsString('产物配置', $text);
        self::assertStringContainsString('平台', $text);
        self::assertStringContainsString('前置检查', $text);
        self::assertStringContainsString('二进制档位', $text);
        self::assertStringContainsString('文件统计', $text);

        // 引擎字段
        self::assertStringContainsString('驱动类', $text);
        self::assertStringContainsString('项目根', $text);
        self::assertStringContainsString('构建目录', $text);

        // 产物字段
        self::assertStringContainsString('PHAR 文件名', $text);
        self::assertStringContainsString('二进制文件名', $text);
        self::assertStringContainsString('签名算法', $text);
        self::assertStringContainsString('内存下限', $text);

        // 平台字段
        self::assertStringContainsString('OS', $text);
        self::assertStringContainsString('架构', $text);
        self::assertStringContainsString('PHP', $text);
        self::assertStringContainsString('openssl', $text);
        self::assertStringContainsString('phar：', $text);
        self::assertStringContainsString('zip：', $text);

        // 下一步提示
        self::assertStringContainsString('下一步', $text);
        self::assertStringContainsString('pack:phar', $text);
        self::assertStringContainsString('pack:verify', $text);
    }

    public function test_text_output_shows_signature_name_not_number(): void
    {
        // 默认签名算法 Phar::SHA256 应显示为 "SHA256" 而非数字
        $result = $this->executeAndCapture([]);
        self::assertStringContainsString('SHA256', $result['text']);
    }

    public function test_text_output_shows_driver_class_name(): void
    {
        $result = $this->executeAndCapture([]);
        self::assertStringContainsString(Packager::class, $result['text']);
    }

    // ─── 3. JSON 模式契约 ──────────────────────────────────────────

    private function decodeJson(array $argv): array
    {
        $result = $this->executeAndCapture($argv);
        // 前缀日志行（[observability] ...）需要剥离
        $text = $result['text'];
        $bracePos = strpos($text, '{');
        self::assertNotFalse($bracePos, 'JSON 输出中应包含 {');
        $json = substr($text, $bracePos);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'JSON 输出应可解码');

        return $decoded;
    }

    public function test_json_top_level_keys(): void
    {
        $payload = $this->decodeJson(['--json']);
        foreach (['engine', 'artifacts', 'platform', 'precheck', 'binary', 'files'] as $key) {
            self::assertArrayHasKey($key, $payload, "缺少顶层键：{$key}");
        }
    }

    public function test_json_engine_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $engine = $payload['engine'];
        self::assertSame(Packager::class, $engine['driver']);
        self::assertSame(Packager::projectRoot(), $engine['projectRoot']);
        self::assertSame(Packager::buildDir(), $engine['buildDir']);
    }

    public function test_json_artifacts_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $artifacts = $payload['artifacts'];
        self::assertSame('kode.phar', $artifacts['pharFilename']);
        self::assertSame('kode.bin', $artifacts['binFilename']);
        self::assertSame(\Phar::SHA256, $artifacts['signatureAlgorithm']);
        self::assertSame(512, $artifacts['memoryLimitMb']);
    }

    public function test_json_platform_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $platform = $payload['platform'];
        self::assertSame(PHP_OS, $platform['os']);
        self::assertSame(PHP_OS_FAMILY, $platform['osFamily']);
        self::assertSame(php_uname('m'), $platform['arch']);
        self::assertSame(PHP_VERSION, $platform['php']);
        self::assertSame(substr(PHP_VERSION, 0, 3), $platform['phpVersion']);
        self::assertIsBool($platform['pharReadonly']);
        self::assertIsBool($platform['openssl']);
        self::assertIsBool($platform['phar']);
        self::assertIsBool($platform['zip']);
    }

    public function test_json_precheck_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $precheck = $payload['precheck'];
        self::assertArrayHasKey('issues', $precheck);
        self::assertArrayHasKey('pass', $precheck);
        self::assertIsArray($precheck['issues']);
        self::assertIsBool($precheck['pass']);
        // pass 与 issues 必须互斥：issues 空 ⇔ pass=true
        self::assertSame($precheck['issues'] === [], $precheck['pass']);
    }

    public function test_json_binary_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $binary = $payload['binary'];
        self::assertArrayHasKey('platformIssues', $binary);
        self::assertArrayHasKey('ready', $binary);
        self::assertIsArray($binary['platformIssues']);
        self::assertIsBool($binary['ready']);
        self::assertSame($binary['platformIssues'] === [], $binary['ready']);
    }

    public function test_json_files_section(): void
    {
        $payload = $this->decodeJson(['--json']);
        $files = $payload['files'];
        self::assertArrayHasKey('count', $files);
        self::assertArrayHasKey('totalBytes', $files);
        self::assertArrayHasKey('totalHuman', $files);
        self::assertIsInt($files['count']);
        self::assertIsInt($files['totalBytes']);
        self::assertIsString($files['totalHuman']);
        // 框架仓库至少有源码与配置文件；具体数量随 include_dirs 与测试环境而异，
        // 只验证非零契约（不绑定精确值，避免受测试间静态状态污染影响）。
        self::assertGreaterThan(0, $files['count']);
        self::assertGreaterThan(0, $files['totalBytes']);
        // humanBytes 单位字符串应包含 B 或 K/M/G
        self::assertMatchesRegularExpression('/[BKMGT]/', $files['totalHuman']);
    }

    // ─── 4. 与 Packager 静态方法一致性 ───────────────────────────

    public function test_json_matches_packager_static_methods(): void
    {
        $payload = $this->decodeJson(['--json']);

        self::assertSame(Packager::driver(), $payload['engine']['driver']);
        self::assertSame(Packager::projectRoot(), $payload['engine']['projectRoot']);
        self::assertSame(Packager::buildDir(), $payload['engine']['buildDir']);
        self::assertSame(Packager::precheck(), $payload['precheck']['issues']);
        self::assertSame(Packager::binaryPlatformIssues(), $payload['binary']['platformIssues']);
    }

    // ─── 5. 覆盖配置 ──────────────────────────────────────────────

    public function test_json_reflects_process_override(): void
    {
        // 进程内覆盖 memory_limit_mb 与 phar_filename，命令应如实反映
        Packager::override([
            'memory_limit_mb' => 1024,
            'phar_filename' => 'myapp.phar',
            'bin_filename' => 'myapp.bin',
        ]);

        $payload = $this->decodeJson(['--json']);
        self::assertSame(1024, $payload['artifacts']['memoryLimitMb']);
        self::assertSame('myapp.phar', $payload['artifacts']['pharFilename']);
        self::assertSame('myapp.bin', $payload['artifacts']['binFilename']);
    }

    public function test_json_reflects_useClass_driver(): void
    {
        // 注册子类作为驱动，命令应显示子类名
        Packager::useClass(SubInfoPackager::class);

        $payload = $this->decodeJson(['--json']);
        self::assertSame(SubInfoPackager::class, $payload['engine']['driver']);
    }
}

/** pack:info 驱动的测试子类。 */
class SubInfoPackager extends Packager
{
}
