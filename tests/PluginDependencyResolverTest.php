<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Plugin\PluginDependencyResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 框架级插件依赖引擎：v1.4.2 下沉到框架后的第一个一等测试。
 *
 * 与 app 侧 tests/PluginDependencyTest.php 分工：
 *   - app 侧验证继承子类的钩子（statusOf/installedVersion 接 DB）与业务动作门禁
 *   - 本文件验证**框架引擎自身**的行为契约——版本约束解析、依赖图算法、
 *     门禁判定、环检测。这些是纯算法，不依赖任何应用状态。
 *
 * 为什么必须在框架测：satisfies() 的分支语义是「声明⟺实现零漂移」的落点，
 * isValidConstraint() 的拒绝列表也是引擎的诚实性承诺。若这里错了，
 * 应用层的门禁形同虚设。
 *
 * 测试用插件名一律 ≥2 字符——NAME_PATTERN 要求 {2,32}，单字符名会被
 * normalizeRequirement() 静默丢弃，导致 graph 构建为空。
 */
final class PluginDependencyResolverTest extends TestCase
{
    protected function setUp(): void
    {
        StubResolver::$plugins = [];
        StubResolver::$status = [];
        StubResolver::$versions = [];
        StubResolver::flush();
    }

    protected function tearDown(): void
    {
        StubResolver::flush();
    }

    // ─── 1. 常量与文档 ─────────────────────────────────────────────

    public function test_constants_are_stable(): void
    {
        self::assertSame('/^[a-z0-9_]{2,32}$/', PluginDependencyResolver::NAME_PATTERN);
        self::assertSame('enabled', PluginDependencyResolver::STATUS_ENABLED);
        self::assertNotSame('', PluginDependencyResolver::VERSION_PATTERN);
        self::assertNotFalse(@preg_match(PluginDependencyResolver::VERSION_PATTERN, '1.0.0'));
    }

    public function test_constraint_syntax_lists_documented_operators(): void
    {
        $syntax = PluginDependencyResolver::constraintSyntax();
        self::assertCount(5, $syntax);
        self::assertStringContainsString('*', $syntax[0]);
        self::assertStringContainsString('>=', $syntax[1]);
        self::assertStringContainsString('^', $syntax[2]);
        self::assertStringContainsString('~', $syntax[3]);
        self::assertStringContainsString('精确', $syntax[4]);
    }

    // ─── 2. 版本格式校验 ───────────────────────────────────────────

    public function test_is_valid_version_matrix(): void
    {
        $valid = ['', '1', '1.0', '1.0.0', '1.0.0.0', 'v1.0', 'v1.0.0-beta.1', '1.0.0+build.5'];
        $invalid = ['abc', '1.0.x', '1.0.*', '@stable', '1..0', '1.0.0.0.0', '1a.2', 'v', 'v1.0.x'];

        foreach ($valid as $v) {
            self::assertTrue(PluginDependencyResolver::isValidVersion($v), "expected valid: '$v'");
        }
        foreach ($invalid as $v) {
            self::assertFalse(PluginDependencyResolver::isValidVersion($v), "expected invalid: '$v'");
        }
    }

    public function test_is_valid_constraint_matrix(): void
    {
        $valid = ['', '*', 'x', 'X', '>=1.0', '<=2.0', '>1.0', '<1.0', '==1.0', '!=1.0',
                  '^1.0', '~1.2', '1.2.3', 'v1.0', '^0.2'];
        $invalid = ['1.0.x', '1.0.*', '@stable', '>=1.0, <2.0', '=>1.0', '=', '>>1.0', 'abc', '1..0'];

        foreach ($valid as $c) {
            self::assertTrue(PluginDependencyResolver::isValidConstraint($c), "expected valid: '$c'");
        }
        foreach ($invalid as $c) {
            self::assertFalse(PluginDependencyResolver::isValidConstraint($c), "expected invalid: '$c'");
        }
    }

    // ─── 3. satisfies() 版本约束引擎 ──────────────────────────────

    #[DataProvider('satisfiesMatrix')]
    public function test_satisfies_matrix(string $version, ?string $constraint, bool $expected): void
    {
        self::assertSame(
            $expected,
            PluginDependencyResolver::satisfies($version, $constraint),
            sprintf("satisfies('%s', %s) 应为 %s", $version, var_export($constraint, true), $expected ? 'true' : 'false')
        );
    }

    /** @return array<string, array{string, ?string, bool}> */
    public static function satisfiesMatrix(): array
    {
        return [
            'wildcard_star'              => ['1.0.0', '*', true],
            'wildcard_null'              => ['99.99.99', null, true],
            'wildcard_x'                 => ['0.1.0', 'x', true],
            'empty_string_constraint'    => ['5.5.5', '', true],

            // PHP version_compare 视为 '1.0.0' > '1.0'（长度不同即不相等），
            // 因此比较运算符测试必须用**同段数**版本。
            'gte_pass'                   => ['1.0.0', '>=1.0.0', true],
            'gte_fail_low'               => ['0.9.9', '>=1.0.0', false],
            'lte_pass'                   => ['2.0.0', '<=2.0.0', true],
            'lte_fail_high'              => ['2.0.1', '<=2.0.0', false],
            'gt_pass'                    => ['1.0.1', '>1.0.0', true],
            'gt_fail_equal'              => ['1.0.0', '>1.0.0', false],
            'lt_pass'                    => ['0.9.0', '<1.0.0', true],
            'lt_fail_equal'              => ['1.0.0', '<1.0.0', false],
            'eq_pass'                    => ['1.0.0', '==1.0.0', true],
            'ne_pass'                    => ['1.0.0', '!=1.0.0', false],

            'caret_pass'                 => ['1.5.0', '^1.0', true],
            'caret_fail_major_bump'      => ['2.0.0', '^1.0', false],
            'caret_fail_below'           => ['0.9.0', '^1.0', false],
            'caret_zero_pass'            => ['0.2.5', '^0.2', true],
            'caret_zero_fail_minor'      => ['0.3.0', '^0.2', false],
            'caret_zero_lower'           => ['0.1.0', '^0.2', false],

            'tilde_pass'                 => ['1.2.5', '~1.2', true],
            'tilde_fail_minor_bump'      => ['1.3.0', '~1.2', false],
            'tilde_fail_below'           => ['1.1.9', '~1.2', false],

            'exact_pass'                 => ['1.2.3', '1.2.3', true],
            'exact_v_prefix_on_version'  => ['v1.2.3', '1.2.3', true],
            'exact_v_on_constraint'      => ['1.2.3', 'v1.2.3', true],
            'exact_fail'                 => ['1.2.3', '1.2.4', false],

            'one_segment'                => ['1', '1', true],
            'caret_on_one_segment'       => ['1.0', '^1', true],
            'four_segment'               => ['1.0.0.0', '1.0.0.0', true],
        ];
    }

    // ─── 4. requirements() 归一 ───────────────────────────────────

    public function test_requirements_normalizes_string_form(): void
    {
        $plugin = new class {
            public function requires(): array { return ['storage_driver']; }
        };
        self::assertSame(
            [['name' => 'storage_driver', 'version' => null]],
            PluginDependencyResolver::requirements($plugin)
        );
    }

    public function test_requirements_normalizes_array_form_with_version(): void
    {
        $plugin = new class {
            public function requires(): array
            {
                return [['name' => 'storage_driver', 'version' => '^1.0']];
            }
        };
        self::assertSame(
            [['name' => 'storage_driver', 'version' => '^1.0']],
            PluginDependencyResolver::requirements($plugin)
        );
    }

    public function test_requirements_lowercases_name(): void
    {
        $plugin = new class {
            public function requires(): array { return ['StorageDriver']; }
        };
        self::assertSame(
            [['name' => 'storagedriver', 'version' => null]],
            PluginDependencyResolver::requirements($plugin)
        );
    }

    public function test_requirements_drops_invalid_entries(): void
    {
        $plugin = new class {
            public function requires(): array
            {
                return [
                    '',                       // 空串
                    'Invalid-Dash',           // 含非法字符
                    'a',                      // 过短（<2）
                    ['version' => '1.0'],     // 缺 name
                    null,                     // 非数组
                    42,                       // 非数组
                ];
            }
        };
        self::assertSame([], PluginDependencyResolver::requirements($plugin));
    }

    public function test_requirements_empty_when_plugin_has_no_requires_method(): void
    {
        $plugin = new class {};
        self::assertSame([], PluginDependencyResolver::requirements($plugin));
    }

    // ─── 5. graph / closure / dependents ─────────────────────────

    public function test_graph_builds_dependency_edges_from_collection(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,   // alpha → beta
            'beta'  => StubPluginBeta::class,    // beta → gamma
            'gamma' => StubPluginGamma::class,   // gamma → []
        ];
        $graph = StubResolver::graph();

        self::assertCount(3, $graph);
        self::assertSame(['beta'], array_column($graph['alpha'], 'name'));
        self::assertSame(['gamma'], array_column($graph['beta'], 'name'));
        self::assertSame([], $graph['gamma']);
    }

    public function test_closure_returns_transitive_dependencies_in_bfs_order(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,
            'beta'  => StubPluginBeta::class,
            'gamma' => StubPluginGamma::class,
        ];
        // alpha → beta → gamma，BFS 从 alpha 出发依次发现 beta、gamma。
        self::assertSame(['beta', 'gamma'], StubResolver::closure('alpha'));
    }

    public function test_closure_empty_for_leaf_plugin(): void
    {
        StubResolver::$plugins = ['gamma' => StubPluginGamma::class];
        self::assertSame([], StubResolver::closure('gamma'));
    }

    public function test_closure_empty_for_unknown_name(): void
    {
        StubResolver::$plugins = ['alpha' => StubPluginAlpha::class];
        self::assertSame([], StubResolver::closure('ghost'));
    }

    public function test_dependents_returns_direct_and_transitive_consumers(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,   // alpha → beta
            'beta'  => StubPluginBeta::class,    // beta → gamma
            'gamma' => StubPluginGamma::class,
        ];
        // gamma 被 beta 直接依赖、被 alpha 间接依赖。
        self::assertSame(['beta', 'alpha'], StubResolver::dependents('gamma'));
    }

    public function test_dependents_empty_for_root_plugin(): void
    {
        StubResolver::$plugins = ['alpha' => StubPluginAlpha::class];
        self::assertSame([], StubResolver::dependents('alpha'));
    }

    // ─── 6. 环检测 ────────────────────────────────────────────────

    public function test_has_cycle_detects_self_dependency(): void
    {
        StubResolver::$plugins = ['selfloop' => StubPluginSelfLoop::class];
        self::assertTrue(StubResolver::hasCycle('selfloop'));
    }

    public function test_has_cycle_detects_two_node_loop(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginToBeta::class,
            'beta'  => StubPluginToAlpha::class,
        ];
        self::assertTrue(StubResolver::hasCycle('alpha'));
        self::assertTrue(StubResolver::hasCycle('beta'));
    }

    public function test_has_cycle_false_for_acyclic_graph(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,
            'beta'  => StubPluginBeta::class,
            'gamma' => StubPluginGamma::class,
        ];
        self::assertFalse(StubResolver::hasCycle('alpha'));
        self::assertFalse(StubResolver::hasCycle('beta'));
        self::assertFalse(StubResolver::hasCycle('gamma'));
    }

    public function test_closure_does_not_infinite_loop_on_cycle(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginToBeta::class,
            'beta'  => StubPluginToAlpha::class,
        ];
        // 从 alpha 出发，BFS 发现 beta 后再次发现 alpha 被 visited 集挡下，返回 ['beta']。
        self::assertSame(['beta'], StubResolver::closure('alpha'));
    }

    // ─── 7. validate() 门禁 ───────────────────────────────────────

    public function test_validate_ok_when_all_deps_are_enabled_and_satisfy_constraint(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlphaConstrained::class,  // alpha → beta(>=1.0)
            'beta'  => StubPluginBeta::class,
            'gamma' => StubPluginGamma::class,
        ];
        StubResolver::$status = ['beta' => 'enabled', 'gamma' => 'enabled'];
        StubResolver::$versions = ['beta' => '1.5.0', 'gamma' => '1.0.0'];

        $result = StubResolver::validate('alpha');
        self::assertTrue($result['ok']);
        self::assertSame([], $result['missing']);
        self::assertSame([], $result['notEnabled']);
        self::assertSame([], $result['versionMismatch']);
        self::assertContains('beta', $result['checked']);
        self::assertContains('gamma', $result['checked']);
    }

    public function test_validate_reports_missing_dependency(): void
    {
        StubResolver::$plugins = ['alpha' => StubPluginAlpha::class];  // alpha → beta（beta 未在 collection 中）
        $result = StubResolver::validate('alpha');
        self::assertFalse($result['ok']);
        self::assertSame(['beta'], $result['missing']);
    }

    public function test_validate_reports_paused_dependency(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,
            'beta'  => StubPluginBeta::class,
            'gamma' => StubPluginGamma::class,
        ];
        StubResolver::$status = ['beta' => 'paused', 'gamma' => 'enabled'];
        $result = StubResolver::validate('alpha');
        self::assertFalse($result['ok']);
        self::assertSame(['beta(paused)'], $result['notEnabled']);
    }

    public function test_validate_reports_version_mismatch(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlphaRequires2::class,  // alpha → beta(^2.0)
            'beta'  => StubPluginBeta::class,
        ];
        StubResolver::$status = ['beta' => 'enabled'];
        StubResolver::$versions = ['beta' => '1.5.0'];  // 不满足 ^2.0

        $result = StubResolver::validate('alpha');
        self::assertFalse($result['ok']);
        self::assertCount(1, $result['versionMismatch']);
        self::assertStringContainsString('beta', $result['versionMismatch'][0]);
        self::assertStringContainsString('^2.0', $result['versionMismatch'][0]);
    }

    public function test_validate_accepts_any_version_when_no_constraint_declared(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,  // alpha → beta（无约束）
            'beta'  => StubPluginBeta::class,   // beta → gamma
            'gamma' => StubPluginGamma::class,
        ];
        StubResolver::$status = ['beta' => 'enabled'];
        StubResolver::$versions = ['beta' => '0.1.0'];

        $result = StubResolver::validate('alpha');
        self::assertTrue($result['ok'], '无约束时应忽略版本，实际：' . json_encode($result));
    }

    public function test_validate_reports_empty_installed_version_as_mismatch_when_constrained(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlphaConstrained::class,
            'beta'  => StubPluginBeta::class,
        ];
        StubResolver::$status = ['beta' => 'enabled'];
        StubResolver::$versions = [];  // beta 未记录版本

        $result = StubResolver::validate('alpha');
        self::assertFalse($result['ok']);
        self::assertCount(1, $result['versionMismatch']);
    }

    // ─── 8. overview() 结构契约 ───────────────────────────────────

    public function test_overview_shape_and_content(): void
    {
        StubResolver::$plugins = [
            'alpha' => StubPluginAlpha::class,
            'beta'  => StubPluginBeta::class,
            'gamma' => StubPluginGamma::class,
        ];
        StubResolver::$status = ['beta' => 'enabled'];
        StubResolver::$versions = ['beta' => '1.0.0'];

        $overview = StubResolver::overview('alpha');

        self::assertArrayHasKey('dependencies', $overview);
        self::assertArrayHasKey('dependents', $overview);
        self::assertArrayHasKey('transitive', $overview);
        self::assertArrayHasKey('cycle', $overview);

        self::assertCount(1, $overview['dependencies']);
        self::assertSame('beta', $overview['dependencies'][0]['name']);
        self::assertNull($overview['dependencies'][0]['version']);
        self::assertSame('1.0.0', $overview['dependencies'][0]['installed']);
        self::assertSame('enabled', $overview['dependencies'][0]['status']);
        self::assertTrue($overview['dependencies'][0]['ok']);

        self::assertSame(['beta', 'gamma'], $overview['transitive']);
        self::assertSame([], $overview['dependents']);
        self::assertFalse($overview['cycle']);
    }

    // ─── 9. flush() 缓存失效 ─────────────────────────────────────

    public function test_flush_clears_graph_and_reverse_caches(): void
    {
        StubResolver::$plugins = ['alpha' => StubPluginAlpha::class];
        StubResolver::flush();
        $firstGraph = StubResolver::graph();
        self::assertSame(['beta'], array_column($firstGraph['alpha'], 'name'));

        StubResolver::$plugins = ['alpha' => StubPluginAlphaConstrained::class, 'beta' => StubPluginBeta::class];
        StubResolver::flush();
        $secondGraph = StubResolver::graph();
        self::assertArrayHasKey('beta', $secondGraph);
    }

    public function test_graph_is_cached_until_flush(): void
    {
        StubResolver::$plugins = ['alpha' => StubPluginAlpha::class];
        $first = StubResolver::graph();
        $second = StubResolver::graph();
        self::assertSame($first, $second);
    }
}

// ─── 测试用插件类 ──────────────────────────────────────────────────

/** alpha → beta（无版本约束）。 */
final class StubPluginAlpha {
    public function requires(): array { return ['beta']; }
}

/** alpha → beta(>=1.0)——约束通过场景。 */
final class StubPluginAlphaConstrained {
    public function requires(): array
    {
        return [['name' => 'beta', 'version' => '>=1.0']];
    }
}

/** alpha → beta(^2.0)——版本不匹配场景。 */
final class StubPluginAlphaRequires2 {
    public function requires(): array
    {
        return [['name' => 'beta', 'version' => '^2.0']];
    }
}

/** beta → gamma。 */
final class StubPluginBeta {
    public function requires(): array { return ['gamma']; }
}

/** gamma → []（叶子节点）。 */
final class StubPluginGamma {
    public function requires(): array { return []; }
}

/** 自环：selfloop → selfloop。 */
final class StubPluginSelfLoop {
    public function requires(): array { return ['selfloop']; }
}

/** alpha → beta（两节点环的一半）。 */
final class StubPluginToBeta {
    public function requires(): array { return ['beta']; }
}

/** beta → alpha（两节点环的另一半）。 */
final class StubPluginToAlpha {
    public function requires(): array { return ['alpha']; }
}

/**
 * 测试用依赖引擎：注入内存态插件集合/状态/版本，绕过 DB。
 */
final class StubResolver extends PluginDependencyResolver
{
    /** @var array<string, class-string> */
    public static array $plugins = [];

    /** @var array<string, string> */
    public static array $status = [];

    /** @var array<string, string> */
    public static array $versions = [];

    protected static function collection(): array
    {
        return self::$plugins;
    }

    protected static function statusOf(string $name): ?string
    {
        return self::$status[$name] ?? null;
    }

    protected static function installedVersion(string $name): string
    {
        return self::$versions[$name] ?? '';
    }
}
