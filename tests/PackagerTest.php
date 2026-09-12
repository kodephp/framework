<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Packaging\Packager;
use PHPUnit\Framework\TestCase;

/**
 * 框架级 Packager 引擎：v1.4.2 下沉到框架后的一等测试。
 *
 * 与 app 侧 tests/AppPackagerTest.php 分工：
 *   - AppPackagerTest 覆盖完整打包流水线（stage/collect/rewrite/toBin/verify/report……）
 *   - 本文件覆盖**只有框架才能自证**的部分——`driver()` 三层分发、`useClass()`
 *     显式注册、配置缺省契约、buildDir 相对/绝对/空值三分支。
 *
 * 为什么 driver() 必须在本仓库测：它是「应用继承即生效」的承重梁。
 * 若 driver() 只看 Packager::class 而非 static::class，AppPackager 声明了
 * 却永远调不到；框架不测就没人兜底。
 */
final class PackagerTest extends TestCase
{
    protected function tearDown(): void
    {
        // 静态状态跨用例污染防护：driver() 与 config() 都用进程内静态缓存。
        Packager::useClass(Packager::class);
        Packager::override([]);
    }

    // ─── 1. driver() 三层分发 ──────────────────────────────────────

    public function test_driver_defaults_to_packager_class_without_config(): void
    {
        // 框架仓库无 config/packaging.php，userConfig() 返回 []，driver() 应回落本类。
        self::assertSame(Packager::class, Packager::driver());
    }

    public function test_useClass_registers_subclass_as_driver(): void
    {
        Packager::useClass(TestSubPackagerA::class);

        self::assertSame(TestSubPackagerA::class, Packager::driver());
    }

    public function test_useClass_accepts_packager_itself(): void
    {
        Packager::useClass(Packager::class);

        self::assertSame(Packager::class, Packager::driver());
    }

    public function test_useClass_rejects_unrelated_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Packager::class);

        Packager::useClass(\stdClass::class);
    }

    public function test_useClass_rejects_nonexistent_class(): void
    {
        // is_subclass_of() 对不存在的类名返回 false，走与 stdClass 相同的拒绝分支。
        $this->expectException(\InvalidArgumentException::class);

        Packager::useClass('Kode\\Framework\\Packaging\\DoesNotExist');
    }

    public function test_driver_caches_resolution_until_explicit_reset(): void
    {
        Packager::useClass(TestSubPackagerA::class);
        $first = Packager::driver();

        // 缓存：不再读配置文件，直接返回上次解析结果。
        // 即使再调 driver() 也应稳定返回同一类名。
        $second = Packager::driver();
        self::assertSame($first, $second);

        // 显式恢复默认后再次调用 driver()，应回到 Packager::class。
        Packager::useClass(Packager::class);
        self::assertSame(Packager::class, Packager::driver());
    }

    public function test_driver_prefers_useClass_over_config_declared_subclass(): void
    {
        // 模拟真实场景：config/packaging.php 声明了子类，进程内 useClass 又声明另一个。
        // 期望：useClass() 优先（它是显式注册，最高优先级）。
        //
        // 无法在框架仓库内真读 config/packaging.php（框架仓库无 config/ 目录），
        // 故仅验证「useClass 写入 → driver() 立即返回」这条确定性路径。
        Packager::useClass(TestSubPackagerB::class);
        self::assertSame(TestSubPackagerB::class, Packager::driver());
    }

    // ─── 2. config() 缺省契约 ────────────────────────────────────

    public function test_config_exposes_documented_default_keys(): void
    {
        $cfg = Packager::config();

        foreach ([
            'build_dir', 'phar_filename', 'bin_filename', 'signature_algorithm',
            'openssl_private_key', 'memory_limit_mb', 'php_version', 'micro_sfx_base',
            'include_dirs', 'include_files', 'exclude_dirs', 'exclude_files',
            'exclude_top_files', 'exclude_ext', 'must_include', 'runtime_dirs',
            'custom_ini',
        ] as $key) {
            self::assertArrayHasKey($key, $cfg, "config() 缺键：{$key}");
        }
        // 'packager' 键应在合并前被 unset（引擎不消费它，仅供 driver() 读取原始值）。
        self::assertArrayNotHasKey('packager', $cfg);
    }

    public function test_config_php_version_defaults_to_current_php_truncated(): void
    {
        $cfg = Packager::config();
        self::assertSame(substr(PHP_VERSION, 0, 3), $cfg['php_version']);
    }

    public function test_override_is_incremental_and_clearable(): void
    {
        $defaults = Packager::config();

        Packager::override(['memory_limit_mb' => 128]);
        $cfg = Packager::config();
        self::assertSame(128, $cfg['memory_limit_mb']);
        // 其余键仍走默认值（增量合并，非覆盖整个配置）。
        self::assertSame($defaults['build_dir'], $cfg['build_dir']);

        Packager::override([]);
        self::assertSame($defaults['memory_limit_mb'], Packager::config()['memory_limit_mb']);
    }

    public function test_override_does_not_leak_between_calls(): void
    {
        Packager::override(['phar_filename' => 'custom.phar']);
        self::assertSame('custom.phar', Packager::config()['phar_filename']);

        Packager::override(['phar_filename' => 'other.phar']);
        self::assertSame('other.phar', Packager::config()['phar_filename']);
    }

    // ─── 3. buildDir 三分支 ──────────────────────────────────────

    public function test_build_dir_empty_string_falls_back_to_project_root(): void
    {
        Packager::override(['build_dir' => '   ']);
        $root = Packager::projectRoot();
        self::assertSame($root, Packager::buildDir());
    }

    public function test_build_dir_relative_path_resolves_against_project_root(): void
    {
        Packager::override(['build_dir' => 'artifacts/release']);
        self::assertSame(
            Packager::projectRoot() . '/artifacts/release',
            Packager::buildDir()
        );
    }

    public function test_build_dir_absolute_path_is_passthrough(): void
    {
        $abs = sys_get_temp_dir() . '/kode_build_test';
        Packager::override(['build_dir' => $abs]);
        // 绝对路径不得再挂项目根——否则 /var/... 会变成 <root>/var/...（实测踩过）。
        self::assertSame($abs, Packager::buildDir());
    }

    // ─── 4. projectRoot 契约 ─────────────────────────────────────

    public function test_project_root_returns_a_directory(): void
    {
        $root = Packager::projectRoot();
        self::assertNotSame('', $root);
        self::assertTrue(is_dir($root), "projectRoot 必须指向真实目录，实际：{$root}");
    }

    public function test_project_root_has_stable_shape_across_calls(): void
    {
        // projectRoot() 有三级降级链，同一进程内结果必须稳定（避免打包中途漂移）。
        $first = Packager::projectRoot();
        $second = Packager::projectRoot();
        self::assertSame($first, $second);
    }

    // ─── 5. humanBytes 契约 ──────────────────────────────────────

    public function test_human_bytes_converts_across_units(): void
    {
        self::assertSame('512 B', Packager::humanBytes(512));
        self::assertSame('1 KB', Packager::humanBytes(1024));
        self::assertSame('1.5 KB', Packager::humanBytes(1536));
        self::assertSame('1 MB', Packager::humanBytes(1048576));
        self::assertSame('1.25 MB', Packager::humanBytes(1310720));
        self::assertSame('1 GB', Packager::humanBytes(1073741824));
    }

    // ─── 6. driver() 缓存可被 useClass 二次覆盖 ──────────────────

    public function test_driver_allows_repeated_useClass_replacement(): void
    {
        // driver() 的缓存语义：一旦 driverClass ≠ Packager::class 就不再重读配置。
        // 但 useClass() 是显式注册口，必须能随时改派——否则子类切换会锁死。
        Packager::useClass(TestSubPackagerA::class);
        self::assertSame(TestSubPackagerA::class, Packager::driver());

        Packager::useClass(TestSubPackagerB::class);
        self::assertSame(TestSubPackagerB::class, Packager::driver());
    }

    public function test_useClass_rejects_class_not_extending_packager_even_if_exists(): void
    {
        // TestSubPackagerNotAPackager 是真实存在的类，但不是 Packager 子类。
        // 契约：不能拿任意已存在类冒充打包器，必须继承引擎。
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(TestSubPackagerNotAPackager::class);

        Packager::useClass(TestSubPackagerNotAPackager::class);
    }
}

/** 最小 Packager 子类（框架测试专用夹具，仅用于断言 LSB 生效）。 */
final class TestSubPackagerA extends Packager
{
}

/** 另一子类：用于验证 useClass() 可切换到不同子类。 */
final class TestSubPackagerB extends Packager
{
}

/** 非 Packager 子类的对照夹具：useClass() 必须拒绝这类「冒名」类。 */
final class TestSubPackagerNotAPackager
{
}
