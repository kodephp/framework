<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成数据库迁移。
 *
 *   bin/kode make:migration create_posts_table
 *   bin/kode make:migration add_price_to_products --force
 */
#[AsCommand(
    name: 'make:migration',
    description: '生成数据库迁移（database/migrations）',
    usage: 'make:migration {name} {--force}',
)]
final class MakeMigrationCommand extends Command
{
    use GeneratesFiles;

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    protected function handle(): int
    {
        $raw = (string) $this->arg(0, '');
        if ($raw === '') {
            $this->error('请提供迁移名：make:migration create_posts_table');

            return 1;
        }

        $name = $this->snake($raw);
        $class = $this->studly($raw);
        $stamp = date('Y_m_d_His');
        $file = "{$stamp}_{$name}.php";

        $content = <<<PHP
<?php

declare(strict_types=1);

use Kode\Framework\Database\Migration;
use Kode\Framework\Database\Schema;

/**
 * {$class}（由 make:migration 生成）
 *
 * 运行：php bin/kode migrate
 * 回滚：php bin/kode migrate:rollback
 */
final class {$class} extends Migration
{
    public function up(): void
    {
        Schema::create('CHANGE_ME', function (Schema \$t): void {
            \$t->id();
            // \$t->string('name', 64);
            // \$t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('CHANGE_ME');
    }
}

PHP;

        $path = $this->path('database/migrations/' . $file);
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成迁移：{$path}");

        return 0;
    }
}
