<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Db\Db;

/**
 * 框架里写错一个 facade 方法名，**不会**在编译期暴露，只会在运行时抛 `\Error`；
 * 而调用点外面那句 `catch (\Throwable)` 会把它吞成「什么都没有」—— 于是
 * 「API 用错」在页面上长成「这台系统没有这项数据」。
 *
 * 这不是假想的病：`ScheduleDispatcher::stats()` 与 `schedule:list` 都调过
 * `Db::selectOne()`，而 kode/database 的 facade **从来没有**那个方法
 * （`Db::__callStatic` 把它当成 Model 的静态方法转发，抛
 * `BadMethodCallException: 请创建 Model 类后使用静态方法调用`）。
 * 因为每次都抛、每次都被吞，「单任务执行统计」这条路从来没有工作过，
 * 管理端那张「执行成功率」卡片一直印着一个合成出来的 100%。
 *
 * 所以这里只钉一件事：`src/` 里对 `Db` 的静态调用，方法名必须真的存在。
 * 两种写法都要扫到 —— `Db::select(...)` 和 `$db::select(...)`
 * （后者是本仓 `$db = \Kode\Database\Db\Db::class` 的既有风格，
 * 只扫前一种的话，上面那个 bug 就正好从缝里漏掉）。
 */
final class DbFacadeCallGateTest extends TestCase
{
    /** 从源码里抽出的「对 Db 的静态调用方法名」清单（已去重、已去注释）。 */
    private const CALL_PATTERN = '/(?:\bDb|\$db)::\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/';

    public function test_the_scanner_actually_runs_over_the_framework_sources(): void
    {
        // 防空转：目录不存在或扫到 0 个调用点时，下面那条「全部存在」会假绿。
        $files = self::sourceFiles();
        self::assertNotEmpty($files, 'src/ 下一个 php 文件都没扫到，本门禁形同虚设');

        $calls = self::collectCalls($files);
        self::assertGreaterThanOrEqual(
            10,
            count($calls),
            '只扫到 ' . count($calls) . ' 个 Db 静态调用 —— 判据大概是被注释剥坏或模式改错了'
        );
    }

    /**
     * 正对照：把「一个不存在的方法名」喂给同一个抽取器，它必须点名。
     * 少了这条，「全部通过」可能只是「什么都没扫到」。
     */
    public function test_a_phantom_facade_call_is_named_by_the_detector(): void
    {
        $detected = self::extract(self::stripComments(
            <<<'PHP'
                <?php
                // Db::selectOne( 出现在注释里，不算调用点
                $row = Db::definitelyNotAFacadeMethod('x');
                $db = \Kode\Database\Db\Db::class;
                return $db::anotherPhantomCall();
                PHP
        ));

        self::assertSame(
            ['definitelyNotAFacadeMethod', 'anotherPhantomCall'],
            $detected,
            '抽取器没抓到这两个（或把注释里的那条也算进来了）'
        );
    }

    /** 反向钉住事实本身：那条把整套统计打死的幻影 API 不许被「顺手补上」。 */
    public function test_select_one_is_not_a_facade_method_and_stays_out_of_call_sites(): void
    {
        self::assertFalse(
            method_exists(Db::class, 'selectOne'),
            'kode/database 新增了 selectOne()：本测试的前提要重读（并回头删掉调用点注释里的解释）'
        );
        self::assertSame([], self::collectCalls(self::sourceFiles(), 'selectOne'));
    }

    public function test_every_static_call_on_the_db_facade_resolves(): void
    {
        $missing = [];
        foreach (self::collectCalls(self::sourceFiles()) as $method) {
            if (!method_exists(Db::class, $method)) {
                $missing[] = $method;
            }
        }

        self::assertSame(
            [],
            $missing,
            '这些方法在 Db facade 上不存在，调用点每次都会抛：' . implode(', ', $missing)
        );
    }

    /**
     * 引用得到就该跑得动：`class_exists` 只核对类名，方法名打错它照样全绿
     * （§6 说的就是这个），所以这类调用点的判据必须走到方法层。
     */
    public function test_the_facade_methods_used_here_are_public_static(): void
    {
        $ref = new \ReflectionClass(Db::class);
        foreach (['select', 'table', 'statement', 'addConnection', 'setDefaultConnection'] as $m) {
            self::assertTrue($ref->hasMethod($m), "Db::{$m}() 不存在");
            $method = $ref->getMethod($m);
            self::assertTrue($method->isPublic() && $method->isStatic(), "Db::{$m}() 不是 public static");
        }
    }

    /* ==================== 工具 ==================== */

    /** @return list<string> src/ 下全部 php 文件（绝对路径） */
    private static function sourceFiles(): array
    {
        $dir = dirname(__DIR__) . '/src';
        self::assertDirectoryExists($dir);

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param  list<string> $files
     * @return list<string> 命中的方法名（去重、排序；传 $only 时只留那一个）
     */
    private static function collectCalls(array $files, ?string $only = null): array
    {
        $found = [];
        foreach ($files as $file) {
            foreach (self::extract(self::stripComments((string) file_get_contents($file))) as $m) {
                if ($only === null || $m === $only) {
                    $found[$m] = true;
                }
            }
        }
        $out = array_keys($found);
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private static function extract(string $code): array
    {
        preg_match_all(self::CALL_PATTERN, $code, $mm);

        return array_values(array_unique($mm[1] ?? []));
    }

    /** 注释里的示例写法不是调用点 —— 不剥掉的话，文档里那句「旧写法长什么样」会被当成实现命中。 */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));

                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
