<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Commands\DbSeedCommand;
use Kode\Framework\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * Seeder 基类与 db:seed 命令测试。
 */
final class SeederTest extends TestCase
{
    public function testCallRunsNestedSeeder(): void
    {
        $parent = new SeederTestParent();
        $parent->run();

        self::assertInstanceOf(SeederTestChild::class, $parent->child);
        self::assertTrue($parent->child->ran);
    }

    public function testDbSeedCommandRunsSeederFile(): void
    {
        $tmp = sys_get_temp_dir() . '/kode_seed_' . uniqid('', true);
        mkdir($tmp . '/database/seeders', 0o755, true);
        $marker = $tmp . '/ran.marker';

        $code = <<<PHP
<?php
declare(strict_types=1);
use Kode\Framework\Database\Seeder;
final class TempSeeder extends Seeder
{
    public function run(): void
    {
        file_put_contents({$this->exportPath($marker)}, '1');
    }
}
PHP;
        file_put_contents($tmp . '/database/seeders/TempSeeder.php', $code);

        $cmd = new DbSeedCommand($tmp);
        $exit = $cmd->fire($this->inputFor($cmd, ['TempSeeder']), new Output(fopen('php://memory', 'w')));

        self::assertSame(0, $exit);
        self::assertFileExists($marker, 'seeder 应已执行并写入标记');

        $this->removeDir($tmp);
    }

    private function exportPath(string $path): string
    {
        return var_export($path, true);
    }

    private function inputFor(\Kode\Framework\Console\Command $cmd, array $argv): Input
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

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}

final class SeederTestChild extends Seeder
{
    public bool $ran = false;

    public function run(): void
    {
        $this->ran = true;
    }
}

final class SeederTestParent extends Seeder
{
    public ?SeederTestChild $child = null;

    public function run(): void
    {
        /** @var SeederTestChild $child */
        $child = $this->call(SeederTestChild::class);
        $this->child = $child;
    }
}
