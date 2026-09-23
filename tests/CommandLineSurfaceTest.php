<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 全部内置控制台命令的「选项面」一致性门禁。
 *
 * 起因（实测）：`kode route:list --group api` 只列出 0 条路由，`--group=api` 才是对的。
 * 根因不在命令代码，而在 `usage` 写成 `[--group=NAME]` 这种方括号形式 —— 签名解析器只认花括号，
 * 于是命令一个选项都没声明，`--group api` 的空格值被当成多余的位置参数丢掉，`opt('group')`
 * 读到一个布尔 true，`(string) true` 拿去过滤自然什么都不剩。
 * 同一个根因还顺带废掉了 `kode help <命令>` 的选项列表，并让「未知选项」无从谈起。
 *
 * 所以这里不是逐个命令写用例，而是把整条规则钉在所有命令上（新加的命令自动进表）：
 *   1. 禁用惰性的方括号 usage；
 *   2. OPTS 名单 ≡ usage 解析出的选项 ≡ 代码里真正读的那些名字；
 *   3. 每个带值选项的 `--x value` 空格写法必须绑到 x 上；
 *   4. 每个命令的 handle() 第一条语句就是 rejectUnknownOptions 守卫，
 *      且所有数值校验排在 resolve()（命令开始连库/拉起 worker 的分界）之前。
 */
final class CommandLineSurfaceTest extends TestCase
{
    /** @var array<string, Signature> 按类名缓存解析结果 */
    private static array $signatures = [];

    /** @return array<string, array{0: class-string<Command>}> */
    public static function commandClasses(): array
    {
        $cases = [];

        foreach (glob(__DIR__ . '/../src/Console/Commands/*.php') ?: [] as $file) {
            $class = 'Kode\\Framework\\Console\\Commands\\' . basename($file, '.php');

            if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                self::fail("命令文件 {$file} 里没有可实例化的 {$class}");
            }

            $cases[basename($file, '.php')] = [$class];
        }

        self::assertGreaterThan(20, count($cases), '命令清单本身漏了，别把这条门禁当空跑');

        return $cases;
    }

    // ---- helpers ----------------------------------------------------------

    private function signatureOf(string $class): Signature
    {
        if (!isset(self::$signatures[$class])) {
            $attrs = (new ReflectionClass($class))->getAttributes(AsCommand::class);
            self::assertNotEmpty($attrs, "{$class} 缺 #[AsCommand]");
            self::$signatures[$class] = new Signature($attrs[0]->newInstance()->usage);
        }

        return self::$signatures[$class];
    }

    /** @return list<string> */
    private function optNames(string $class): array
    {
        $names = array_keys($this->signatureOf($class)->getOptions());
        sort($names);

        return $names;
    }

    /**
     * 命令代码里实际读到的选项名（含 checkIntOptions/checkNumOptions 的名单）
     *
     * @return list<string>
     */
    private function readNames(string $class): array
    {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        $names = [];

        if (preg_match_all("/->(?:opt|flag|provided)\(\s*'([a-z0-9_-]+)'/", $src, $m) === false) {
            self::fail("无法扫描 {$class} 源码");
        }

        foreach ($m[1] as $name) {
            $names[$name] = true;
        }

        // 数值校验的名单是数组字面量，上面那条正则抓不到
        if (preg_match_all("/->check(?:Int|Num)Options\(\[(.*?)\]/s", $src, $calls) === false) {
            self::fail("无法扫描 {$class} 的数值选项名单");
        }

        foreach ($calls[1] as $list) {
            preg_match_all("/'([a-z0-9_-]+)'/", $list, $items);

            foreach ($items[1] as $name) {
                $names[$name] = true;
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    // ---- 1. 用法串必须是可解析的签名 DSL ------------------------------------

    #[DataProvider('commandClasses')]
    public function test_usage_does_not_use_the_inert_bracket_form(string $class): void
    {
        $usage = $this->usageOf($class);

        self::assertStringNotContainsString(
            '[--',
            $usage,
            "{$class} 的 usage 里还有 [--opt] 这种写法：签名解析不出任何选项，"
            . '空格形式的值会泄成位置参数，kode help 也列不出选项'
        );
    }

    private function usageOf(string $class): string
    {
        return (new ReflectionClass($class))->getAttributes(AsCommand::class)[0]->newInstance()->usage;
    }

    #[DataProvider('commandClasses')]
    public function test_declared_options_are_reachable_from_the_usage_string(string $class): void
    {
        $opts = (new ReflectionClass($class))->getConstant('OPTS');

        self::assertIsArray($opts, "{$class} 必须声明 private const OPTS");
        self::assertSame($this->optNames($class), array_values((function (array $o): array {
            sort($o);

            return $o;
        })($opts)), "{$class} 的 OPTS 与 usage 解析出的选项不一致：一边写了、一边读不到");
    }

    #[DataProvider('commandClasses')]
    public function test_every_option_the_code_reads_is_declared_in_usage(string $class): void
    {
        $declared = $this->optNames($class);
        $missing = array_values(array_diff($this->readNames($class), $declared));

        self::assertSame([], $missing, "{$class} 读了 usage 里没声明的选项：它永远只能拿到布尔 true 或默认值");
    }

    /**
     * 守卫必须是 handle() 的第一条语句（前面只允许注释和空白）。
     *
     * 「挂在某处」不够：放在一半业务之后，前半段已经写库/发信了才告诉用户选项打错。
     */
    #[DataProvider('commandClasses')]
    public function test_guard_is_the_first_thing_handle_does(string $class): void
    {
        $body = $this->handleBody($class);
        $at = strpos($body, '$this->rejectUnknownOptions(self::OPTS)');

        self::assertNotFalse($at, "{$class} 没有接未知选项门禁");

        // 守卫自己那行（`if (($bad = ` 开头）不算「前面的语句」，只看它之上
        $lineStart = strrpos(substr($body, 0, (int) $at), "\n");
        $prefix = $lineStart === false ? '' : substr($body, 0, (int) $lineStart);

        self::assertSame(
            [],
            array_values(array_filter(
                array_map('trim', explode("\n", $prefix)),
                static fn (string $line): bool => $line !== ''
                    && !str_starts_with($line, '//')
                    && $line !== '{'
                    && !str_starts_with($line, 'protected function handle')
            )),
            "{$class} 的守卫前面还有别的语句：校验必须排在任何业务动作之前"
        );
    }

    /** handle() 的函数体（含花括号配对，不含外层）。 */
    private function handleBody(string $class): string
    {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        $start = strpos($src, 'function handle(');

        self::assertNotFalse($start, "找不到 {$class} 的 handle()");

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;

        for ($i = $open, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($src, $open + 1, $i - $open - 1);
                }
            }
        }

        self::fail("{$class} 的 handle() 花括号不配对");
    }

    /**
     * 参数校验必须排在命令动手之前。
     *
     * `resolve()` 是「开始干活」的分界：它可能连上数据库、拉起 worker、订阅频道。
     * 校验放后面就成了「先连库再报参数错」——`migrate --step=abc --pretend` 会在报错前
     * 已经把连接建起来。这条断言把「先验后跑」钉在源码结构上，守卫被挪到 resolve() 之后
     * 立刻变红，而不是靠测试进程里容器没启动侥幸抛异常。
     */
    #[DataProvider('commandClasses')]
    public function test_validators_run_before_the_command_starts_working(string $class): void
    {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        $started = strpos($src, 'resolve(');

        if ($started === false) {
            self::markTestSkipped("{$class} 不碰容器，无先后可言");
        }

        $checked = 0;

        foreach (['$this->rejectUnknownOptions(self::OPTS)', '$this->checkIntOptions(', '$this->checkNumOptions('] as $needle) {
            $offset = 0;

            while (($pos = strpos($src, $needle, $offset)) !== false) {
                self::assertLessThan($started, $pos,
                    "{$class} 的 {$needle} 排在 resolve() 之后：先动起手再报参数错");
                $checked++;
                $offset = $pos + 1;
            }
        }

        self::assertGreaterThan(0, $checked, "{$class} 碰了容器却一处校验都没有");
    }

    // ---- 2. 三种书写形式都要落到选项上 --------------------------------------

    #[DataProvider('commandClasses')]
    public function test_every_declared_option_can_actually_be_read(string $class): void
    {
        $signature = $this->signatureOf($class);
        self::assertSame($signature->getOptions() === [], (array) (new ReflectionClass($class))->getConstant('OPTS') === [],
            "{$class}: usage 解析不出选项，OPTS 却非空（或反之）——两边必须同时为空/非空");

        foreach ($signature->getOptions() as $option) {
            if (!$option->acceptsValue) {
                $flag = new Input(['kode', '--' . $option->name], $signature);
                self::assertTrue($flag->flag($option->name), "--{$option->name} 布尔形态失效");

                continue;
            }

            // 空格写法：route:list --group api 那类事故的回归位
            $spaced = new Input(['kode', '--' . $option->name, 'sentinel'], $signature);
            self::assertSame('sentinel', $spaced->opt($option->name),
                "{$class} 的 --{$option->name} 空格写法没绑上值");
            self::assertTrue($spaced->provided($option->name), "--{$option->name} 传了却不被视为已提供");

            $equals = new Input(['kode', '--' . $option->name . '=sentinel'], $signature);
            self::assertSame('sentinel', $equals->opt($option->name), "--{$option->name}=value 形态失效");
        }
    }

    // ---- 3. 未知选项归类 ----------------------------------------------------

    #[DataProvider('commandClasses')]
    public function test_unknown_options_are_reported_for_every_command(string $class): void
    {
        $cmd = new $class();
        $signature = $this->signatureOf($class);
        $known = (new ReflectionClass($class))->getConstant('OPTS');

        $cmd = $this->withInput($cmd, new Input(['kode', '--definitely-not-an-option'], $signature));

        self::assertSame(['--definitely-not-an-option'], $this->classify($cmd, array_values($known)));
    }

    #[DataProvider('commandClasses')]
    public function test_own_options_are_never_reported_as_unknown(string $class): void
    {
        $cmd = new $class();
        $signature = $this->signatureOf($class);
        $known = array_values((new ReflectionClass($class))->getConstant('OPTS'));

        $argv = ['kode'];

        foreach ($signature->getOptions() as $option) {
            $argv[] = $option->acceptsValue ? '--' . $option->name . '=1' : '--' . $option->name;
        }

        $cmd = $this->withInput($cmd, new Input($argv, $signature));

        self::assertSame([], $this->classify($cmd, $known), "{$class} 把自己的选项报成了未知");
    }

    /**
     * @param list<string> $known
     *
     * @return list<string>
     */
    private function classify(Command $cmd, array $known): array
    {
        return array_values((new ReflectionMethod(Command::class, 'unknownOptions'))->invoke($cmd, $known));
    }

    private function withInput(Command $cmd, Input $input): Command
    {
        (new ReflectionProperty($cmd, 'input'))->setValue($cmd, $input);

        return $cmd;
    }

    // ---- 4. 数值选项校验（桩命令，不碰任何后端） ------------------------------

    /** @return array<string, array{0: list<string>, 1: int}> */
    public static function badNumbers(): array
    {
        return [
            '字母' => [['--step=abc'], 1],
            '小数' => [['--step=1.5'], 1],
            '科学计数' => [['--step=1e3'], 1],
            '零步' => [['--step=0'], 1],
            '负步' => [['--step=-2'], 1],
            '合法值' => [['--step=2'], 42],
            '没传走默认' => [['--sleep=1.5'], 42],
            '数值项收小数' => [['--sleep=0.25'], 42],
            '数值项拒字母' => [['--sleep=soon'], 1],
        ];
    }

    #[DataProvider('badNumbers')]
    public function test_numeric_options_are_validated_before_the_body_runs(array $argv, int $expected): void
    {
        $result = $this->fire(new NumberStubCommand(), $argv);

        self::assertSame($expected, $result['code'], $result['text']);

        if ($expected === 1) {
            self::assertStringContainsString('需要', $result['text']);
        }
    }

    #[DataProvider('badNumbers')]
    public function test_an_invalid_number_short_circuits_before_the_body(array $argv, int $expected): void
    {
        $result = $this->fire(new NumberStubCommand(), $argv);

        self::assertSame($expected === 42, str_contains($result['text'], 'body-ran'),
            '校验没过却仍然进了命令体，或校验过了却没进');
    }

    public function test_a_bare_value_option_is_rejected_by_the_console_layer(): void
    {
        // `{--step=}` 的「一旦用了就必须给值」由 console 的 Command::validate 兜住，
        // 命令体根本不该拿到一个 null 去 (int) 强转成 0
        $signature = $this->signatureOf(NumberStubCommand::class);
        $cmd = new NumberStubCommand();
        $out = $this->openOutput();

        self::assertFalse($cmd->validate(new Input(['kode', '--step'], $signature), $out[0]),
            '光秃秃的 --step 必须在校验层就失败');
    }

    public function test_the_stub_proves_the_help_token_is_not_a_command_concern(): void
    {
        // --help / -h 由 Kernel 在 fire() 之前按 flags('help') 处理（实测命令体不执行）。
        // 命令侧那份 help 分支只服务直接 fire() 的嵌入方，这里把它钉住，别让下次读到旧说法。
        $result = $this->fire(new NumberStubCommand(), ['--help']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('用法:', $result['text']);
        self::assertStringNotContainsString('body-ran', $result['text']);
    }

    /** @return array{0: Output, 1: resource} */
    private function openOutput(): array
    {
        $stream = fopen('php://temp', 'r+');

        return [new Output($stream, $stream), $stream];
    }

    /** @return array{code: int, text: string} */
    private function fire(Command $cmd, array $argv): array
    {
        $signature = $this->signatureOf($cmd::class);
        [$out, $stream] = $this->openOutput();
        $code = $cmd->fire(new Input(array_merge(['kode'], $argv), $signature), $out);

        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);

        return ['code' => $code, 'text' => $text];
    }
}

/**
 * 数值校验桩命令：handle() 的返回码就是「校验有没有放行」。
 * 42 = 进了命令体；1 = 被 checkIntOptions/checkNumOptions 拦下。
 */
#[AsCommand(
    name: 'stub:numbers',
    description: '校验整数/数值选项的桩命令',
    usage: 'stub:numbers {--step=} {--sleep=}',
)]
final class NumberStubCommand extends Command
{
    /** 本命令认识的选项 */
    private const OPTS = ['step', 'sleep'];

    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        if (($bad = $this->checkIntOptions(['step'], 1)) !== null) {
            return $bad;
        }

        if (($bad = $this->checkNumOptions(['sleep'], 0)) !== null) {
            return $bad;
        }

        $this->line('body-ran');

        return 42;
    }
}
