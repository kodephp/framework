<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Database\Seeder;

/**
 * 运行数据库填充。
 *
 *   bin/kode db:seed                       # 运行 DatabaseSeeder
 *   bin/kode db:seed --class=UsersTableSeeder
 */
#[AsCommand(
    name: 'db:seed',
    description: '运行数据库填充（database/seeders）',
    usage: 'db:seed {--class=}',
)]
final class DbSeedCommand extends Command
{
    /** 项目根（测试可注入）。 */
    protected string $basePath = '';

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    protected function handle(): int
    {
        $root = $this->basePath !== '' ? rtrim($this->basePath, '/') : getcwd();
        $seedersDir = $root . '/database/seeders';

        if (!is_dir($seedersDir)) {
            $this->ensureDir($seedersDir);
            $this->warn("seeders 目录不存在，已创建：{$seedersDir}");
        }

        $class = (string) ($this->opt('class', '') ?: $this->arg(0, 'DatabaseSeeder'));
        if ($class === '') {
            $class = 'DatabaseSeeder';
        }

        // 一次性 require 整个 seeders 目录，使 call(OtherSeeder::class) 可解析（默认无命名空间）。
        foreach (glob($seedersDir . '/*.php') ?: [] as $file) {
            require_once $file;
        }

        if (!class_exists($class)) {
            $this->error("Seeder 类不存在：{$class}（请先创建 database/seeders/{$class}.php）");

            return 1;
        }

        $seeder = new $class();
        if (!$seeder instanceof Seeder) {
            $this->error("{$class} 必须继承 " . Seeder::class);

            return 1;
        }

        $this->info("开始运行 seeder：{$class}");
        $seeder->run();
        $this->success("seeder 完成：{$class}");

        return 0;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建目录：{$dir}");
        }
    }
}
