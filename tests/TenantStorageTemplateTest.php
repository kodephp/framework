<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Core\Config\Config;
use Kode\Framework\Console\Commands\TenantStorageCommand;
use Kode\Framework\Providers\TenantStorageServiceProvider;
use Kode\Framework\Tenant\Storage\TenantConnectionResolver;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * tenant.storage.template 的取值口径（v1.7.6）。
 *
 * 修复前：模板名在 connections 里查不到时，静默借用「默认连接」的凭证，
 * 于是配错的模板既不报错、也让租户库跑在另一套连接参数上——租户隔离失效于无声。
 * 现在：留空 = 明确跟随 database.default；显式配错 = 启动即失败。
 */
final class TenantStorageTemplateTest extends TestCase
{
    /** @var array<string, mixed> 用例改过的键，结束后回写 */
    private array $restore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        /** @var Config $config */
        $config = app()->container->get(Config::class);
        foreach ($this->restore as $key => $value) {
            $config->set($key, $value);
        }
    }

    private function overwrite(string $key, mixed $value): void
    {
        /** @var Config $config */
        $config = app()->container->get(Config::class);
        $this->restore += [$key => $config->get($key)];
        $config->set($key, $value);
    }

    private function register(): TenantConnectionResolver
    {
        $container = app()->container;
        (new TenantStorageServiceProvider($container))->register();

        return $container->get(TenantConnectionResolver::class);
    }

    #[RunInSeparateProcess]
    public function testBlankTemplateFollowsDefaultConnection(): void
    {
        $this->overwrite('database.default', 'sqlite');
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            'template' => '',
            'prefix' => 'tnt_',
            'map' => [],
            'on_missing' => 'fallback',
        ]);

        $resolved = $this->register()->resolve('acme');

        self::assertIsArray($resolved);
        // 凭证取自默认连接（sqlite），而不是写死的模板名。
        self::assertSame('sqlite', $resolved['driver']);
        self::assertSame('tnt_acme', $resolved['database']);
    }

    #[RunInSeparateProcess]
    public function testWhitespaceOnlyTemplateAlsoFollowsDefaultConnection(): void
    {
        $this->overwrite('database.default', 'sqlite');
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            // env('TENANT_STORAGE_TEMPLATE', '') 在 .env 里写了空值时会得到空格串，
            // 不能当成「配置了一个叫空格的连接」直接报错。
            'template' => '   ',
            'prefix' => 'tnt_',
        ]);

        $resolved = $this->register()->resolve('acme');

        self::assertIsArray($resolved);
        self::assertSame('sqlite', $resolved['driver']);
    }

    #[RunInSeparateProcess]
    public function testMissingTemplateKeyAlsoFollowsDefaultConnection(): void
    {
        $this->overwrite('database.default', 'sqlite');
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            'prefix' => 'tnt_',
        ]);

        $resolved = $this->register()->resolve('acme');

        self::assertIsArray($resolved);
        self::assertSame('sqlite', $resolved['driver']);
    }

    #[RunInSeparateProcess]
    public function testUnknownTemplateFailsFastInsteadOfBorrowingDefault(): void
    {
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            'template' => 'mysql_replica',
            'prefix' => 'tnt_',
        ]);

        try {
            $this->register();
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('mysql_replica', $message);
            // 报错要列出可用连接，否则运维还得翻配置文件。
            self::assertStringContainsString('sqlite', $message);

            return;
        }

        self::fail('配错的模板连接应在启动期抛出异常');
    }

    #[RunInSeparateProcess]
    public function testKnownTemplateIsHonouredOverDefaultConnection(): void
    {
        $this->overwrite('database.default', 'sqlite');
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            'template' => 'mysql',
            'prefix' => 'tnt_',
        ]);

        $resolved = $this->register()->resolve('acme');

        self::assertIsArray($resolved);
        self::assertSame('mysql', $resolved['driver']);
        self::assertSame('tnt_acme', $resolved['database']);
    }

    /** 诊断命令必须报出「实际生效」的模板连接，而不是重抄一遍配置里的写死默认值。 */
    #[RunInSeparateProcess]
    public function testDiagnosticCommandReportsEffectiveTemplate(): void
    {
        $this->overwrite('database.default', 'sqlite');
        $this->overwrite('tenant.storage', [
            'enabled' => true,
            'strategy' => 'database',
            'template' => '',
            'prefix' => 'tnt_',
        ]);

        $handle = fopen('php://memory', 'w');
        (new TenantStorageCommand())->fire(
            new Input(['tenant:storage:list', '--json']),
            new Output($handle)
        );
        rewind($handle);
        $summary = json_decode((string) stream_get_contents($handle), true);

        self::assertIsArray($summary);
        self::assertSame('sqlite', $summary['template']);
    }

    /** 配置模板不得把模板名写死成 connections 里不存在的连接（否则装机即触发上面的失败）。 */
    public function testShippedConfigTemplateResolvesAgainstItsOwnConnections(): void
    {
        /** @var array<string, mixed> $tenant */
        $tenant = require self::SKELETON_ROOT . '/config/tenant.php';
        /** @var array<string, mixed> $database */
        $database = require self::SKELETON_ROOT . '/config/database.php';

        $template = trim((string) ($tenant['storage']['template'] ?? ''));
        $connections = (array) ($database['connections'] ?? []);

        self::assertArrayHasKey('storage', $tenant);
        self::assertTrue(
            $template === '' || array_key_exists($template, $connections),
            "tenant.storage.template 默认值 \"{$template}\" 不在 database.connections 里"
        );
    }
}
