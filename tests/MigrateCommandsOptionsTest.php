<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Kernel;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\MigrateCommand;
use Kode\Framework\Console\Commands\MigrateResetCommand;
use Kode\Framework\Console\Commands\MigrateRollbackCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * migrate 家族的选项面：未知选项必须报错，内核全局标志必须放行。
 *
 * 实测踩到：`kode migrate --pretend` 在 --pretend 尚不是本命令选项时被判「未知」后静默忽略，
 * 迁移照常落库、退出码 0 —— 写库命令上「以为传了 dry-run、实际全量执行」是最贵的误会。
 * 反方向同样致命：把 `-v` 这类内核自己消费的全局标志也报成未知，命令就没法带日志级别跑。
 *
 * 全部断言都只在 `resolve(Migrator::class)` 之前的分支上跑：测试不碰任何真实数据库。
 */
final class MigrateCommandsOptionsTest extends TestCase
{
    /** @return array<string, array{0: class-string<Command>, 1: list<string>}> */
    public static function commands(): array
    {
        return [
            'migrate' => [MigrateCommand::class, ['--step=2', '--pretend']],
            'migrate:rollback' => [MigrateRollbackCommand::class, ['--step=2']],
            'migrate:reset' => [MigrateResetCommand::class, []],
        ];
    }

    // ---- helpers ----------------------------------------------------------

    private function make(string $class): Command
    {
        return new $class();
    }

    private function inputFor(Command $cmd, array $argv): Input
    {
        $attrs = (new ReflectionClass($cmd))->getAttributes(AsCommand::class);
        $usage = $attrs === [] ? '' : $attrs[0]->newInstance()->usage;

        return new Input(array_merge(['kode'], $argv), $usage !== '' ? new Signature($usage) : null);
    }

    /**
     * 直接问命令的归类结果（不进 handle()，所以不会碰到数据库）
     *
     * @param class-string<Command> $class
     *
     * @return list<string>
     */
    private function classify(string $class, array $argv): array
    {
        $cmd = $this->make($class);
        (new ReflectionProperty($cmd, 'input'))->setValue($cmd, $this->inputFor($cmd, $argv));
        $known = (new ReflectionClass($cmd))->getConstant('OPTS') ?? [];

        return (new ReflectionMethod(Command::class, 'unknownOptions'))
            ->invoke($cmd, array_values($known));
    }

    /**
     * @param class-string<Command> $class
     *
     * @return array{code: int, text: string}
     */
    private function fireCmd(string $class, array $argv): array
    {
        return $this->fire($this->make($class), $argv);
    }

    private function fire(Command $cmd, array $argv): array
    {
        $stream = fopen('php://temp', 'r+');
        // warn()/error() 走错误流：只接输出流会漏掉它们（消息打到终端，测试读到空串）
        $code = $cmd->fire($this->inputFor($cmd, $argv), new Output($stream, $stream));

        rewind($stream);
        $text = (string) stream_get_contents($stream);
        fclose($stream);

        return ['code' => $code, 'text' => $text];
    }

    // ---- 未知选项 ----------------------------------------------------------

    #[DataProvider('commands')]
    public function test_unknown_options_are_reported(string $class): void
    {
        self::assertSame(['--bogus'], $this->classify($class, ['--bogus', 'x']));
        self::assertSame(['--dry-run'], $this->classify($class, ['--dry-run']),
            'dry-run 是「以为传了 dry-run、实际全量执行」的典型写法');
    }

    /**
     * 数据提供的选项必须就是命令的 OPTS 名单：否则「自家选项被放行」测的是一份命令根本不认识的表。
     * migrate:reset 什么都不接受，所以名单为空本身就是它要核对的期望。
     */
    #[DataProvider('commands')]
    public function test_own_options_are_accepted(string $class, array $own): void
    {
        $names = array_map(
            static fn (string $token): string => explode('=', substr($token, 2), 2)[0],
            $own
        );
        self::assertSame($names, array_values((new ReflectionClass($class))->getConstant('OPTS') ?? []),
            "{$class} 的 OPTS 名单与测试提供的自有选项不一致");

        foreach ($own as $token) {
            self::assertSame([], $this->classify($class, [$token]), "{$class} 把自己的选项判成了未知");
        }
    }

    /**
     * `--pretend=false` 这种带值写法仍然认识同一个名字：判成未知会在报错里指错地方。
     */
    #[DataProvider('commands')]
    public function test_a_flag_spelled_with_a_value_is_still_recognised(string $class, array $own): void
    {
        foreach ($own as $token) {
            $withValue = explode('=', $token, 2)[0] . '=false';
            self::assertSame([], $this->classify($class, [$withValue]), "{$withValue} 被判成了未知选项");
        }

        // 带值写法不该给未知选项开后门
        self::assertSame(['--bogus'], $this->classify($class, ['--bogus=false']),
            "{$class} 让带值的未知选项溜过去了");
    }

    /**
     * `--pretend` 只对 migrate 有意义：在 reset 上它必须被拦下。
     *
     * 这正是最初踩到的形状 —— reset 没有 --pretend，静默放行等于「操作者以为在看回放，
     * 实际把全库回滚了」，而退出码仍是 0。
     */
    public function test_pretend_is_only_known_to_migrate(): void
    {
        self::assertSame([], $this->classify(MigrateCommand::class, ['--pretend']));
        self::assertSame(['--pretend'], $this->classify(MigrateResetCommand::class, ['--pretend']));
        self::assertSame(['--pretend'], $this->classify(MigrateRollbackCommand::class, ['--pretend']));
    }

    /**
     * 拼法差异不是同义词：console 只做「短别名 → 长名」归一，`--dry_run` 命令侧读不到。
     * 门禁替用户「顺手容错」就等于把「传了个没人读的选项、命令照原样执行」再放行一次。
     */
    public function test_a_near_miss_spelling_is_still_unknown(): void
    {
        self::assertSame(['--dry_run'], $this->classify(MigrateCommand::class, ['--dry_run']));
        self::assertSame(['--no_color'], $this->classify(MigrateCommand::class, ['--no_color']),
            '全局标志 --no-color 的下划线写法既不被内核消费，也不该被命令放行');
        self::assertSame(['--prEtEnd'], $this->classify(MigrateCommand::class, ['--prEtEnd']));
    }

    #[DataProvider('commands')]
    public function test_kernel_global_flags_are_accepted(string $class): void
    {
        foreach (array_keys(Kernel::GLOBAL_FLAGS) as $token) {
            self::assertSame([], $this->classify($class, [$token]), "全局标志 {$token} 被自家命令判成未知");
        }
    }

    #[DataProvider('commands')]
    public function test_global_flags_combine_with_command_options(string $class, array $own): void
    {
        $argv = ['-v', '--no-ansi'];
        foreach ($own as $token) {
            $argv[] = $token;
        }
        self::assertSame([], $this->classify($class, $argv));
        self::assertSame(['--bogus'], $this->classify($class, [...$argv, '--bogus']));
    }

    /**
     * 拦在 resolve() 之前：报错 + 非 0 退出，且绝不碰数据库
     */
    #[DataProvider('commands')]
    public function test_fire_rejects_unknown_options(string $class): void
    {
        $result = $this->fireCmd($class, ['--dry-run']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('未识别的选项', $result['text']);
        self::assertStringContainsString('--dry-run', $result['text']);
        self::assertStringContainsString('本命令支持的用法', $result['text']);
    }

    /**
     * 门禁只该拦住读不到的名字：认得的选项必须放行到命令体。
     *
     * 这里不能用真 migrate 命令跑 —— 它的 handle() 在门禁之后第一步就是
     * `resolve(Migrator::class)`，而全套测试同进程执行时容器可能已被别的用例 boot 起来，
     * 那时它就是「拿真实数据库跑一遍迁移」。替身命令除了基类门禁什么都不做，42 表示越过了门禁。
     */
    public function test_accepted_options_reach_the_command_body(): void
    {
        $stub = new class('migrate:stub', '', 'migrate:stub [--step=N] [--pretend]') extends Command {
            protected function handle(): int
            {
                return $this->rejectUnknownOptions(['step', 'pretend']) ?? 42;
            }
        };

        $passed = $this->fire($stub, ['--pretend', '--step=2', '-v']);
        self::assertSame(42, $passed['code'], '门禁把自家选项或全局标志拦下了');
        self::assertSame('', $passed['text'], '放行时不该有任何输出');

        $blocked = $this->fire($stub, ['--pretend', '--dry-run']);
        self::assertSame(1, $blocked['code']);
        self::assertStringContainsString('--dry-run', $blocked['text']);
    }

    /**
     * `kode migrate -h` 的旧形状：'-h' 不是已知选项 → 静默忽略 → 迁移照跑、退出 0。
     * 问「怎么写」的词必须先出帮助页，绝不能走进命令体。
     */
    #[DataProvider('commands')]
    public function test_help_tokens_show_help_instead_of_running(string $class): void
    {
        foreach (['--help', '-h', '-hq'] as $token) {
            $result = $this->fireCmd($class, [$token]);

            self::assertSame(0, $result['code'], "带 {$token} 时执行了命令体");
            self::assertStringContainsString('用法:', $result['text'], "带 {$token} 时没出帮助页");
            self::assertStringNotContainsString('未识别的选项', $result['text']);
        }
    }

    /**
     * 自己声明了 `--help` 选项的命令优先用自家的：内置帮助页只兜「没人认领」的情况。
     */
    public function test_a_command_can_own_its_help_option(): void
    {
        $stub = new class('migrate:stub', '', 'migrate:stub [--help]') extends Command {
            protected function handle(): int
            {
                return $this->rejectUnknownOptions(['help']) ?? 42;
            }
        };

        $result = $this->fire($stub, ['--help']);
        self::assertSame(42, $result['code'], '自家 --help 被内置帮助页抢了');
        self::assertSame('', $result['text']);
    }

    // ---- --step 取值 ------------------------------------------------------

    #[DataProvider('stepCommands')]
    public function test_a_non_positive_step_is_rejected(string $class, array $argv): void
    {
        $result = $this->fireCmd($class, $argv);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('--step', $result['text']);
    }

    /**
     * @return array<string, array{0: class-string<Command>, 1: list<string>}>
     */
    public static function stepCommands(): array
    {
        return [
            // (int) 强转把 'abc' 变成 0：migrate 一步不跑、rollback 变成「回滚 0 个批次」，都静默退出 0
            'migrate 非数字' => [MigrateCommand::class, ['--step=abc']],
            'migrate 零' => [MigrateCommand::class, ['--step=0']],
            'migrate 负数' => [MigrateCommand::class, ['--step=-1']],
            'migrate 只写标志没给值' => [MigrateCommand::class, ['--step']],
            // 实测踩过：'numeric' 放过小数值，(int) 再把它截成 1 —— 撤的不是说好的那几个批次
            'migrate 小数' => [MigrateCommand::class, ['--step=1.5']],
            'migrate 科学计数' => [MigrateCommand::class, ['--step=1e3']],
            'rollback 非数字' => [MigrateRollbackCommand::class, ['--step=abc']],
            'rollback 小数' => [MigrateRollbackCommand::class, ['--step=2.5']],
            'rollback 只写标志没给值' => [MigrateRollbackCommand::class, ['--step']],
        ];
    }

    /**
     * usage 必须是 console 的签名 DSL（`{--step=}`）：写成 `[--step=N]` 解析不出任何选项，
     * 于是 `--step 2` 的空格写法把 2 泄成位置参数、`kode help migrate` 也列不出选项。
     */
    #[DataProvider('stepCommandsUsage')]
    public function test_the_space_separated_value_binds_to_the_option(string $class): void
    {
        $cmd = $this->make($class);
        $input = $this->inputFor($cmd, ['--step', '2']);

        self::assertSame('2', $input->opt('step'), "{$class} 的 usage 不是签名 DSL，空格写法没绑上 --step");
        self::assertSame([], $input->args(), "--step 2 的 2 泄成了位置参数");
    }

    /** @return array<string, array{0: class-string<Command>}> */
    public static function stepCommandsUsage(): array
    {
        return [
            'migrate' => [MigrateCommand::class],
            'migrate:rollback' => [MigrateRollbackCommand::class],
        ];
    }

    // ---- 用法行与认识表对齐 ------------------------------------------------

    /**
     * OPTS 里列出的选项必须出现在 usage 上
     *
     * 两处一旦漂移：usage 少了的选项用户无从得知，OPTS 少了的选项会被自家命令报错。
     */
    #[DataProvider('commands')]
    public function test_declared_options_are_reachable_from_the_usage_string(string $class, array $own): void
    {
        $attrs = (new ReflectionClass($class))->getAttributes(AsCommand::class);
        self::assertNotSame([], $attrs);
        $usage = $attrs[0]->newInstance()->usage;
        // 用 hasConstant 而不是 getConstant() ?? []：常量没声明时后者静默给空表，测试会跟着空转
        self::assertTrue((new ReflectionClass($class))->hasConstant('OPTS'), "{$class} 没有 OPTS 名单，未知选项门禁形同虚设");
        $known = (new ReflectionClass($class))->getConstant('OPTS') ?? [];

        foreach ($known as $name) {
            self::assertStringContainsString((string) $name, $usage, "选项 {$name} 认识却没写进 usage");
        }
    }
}
