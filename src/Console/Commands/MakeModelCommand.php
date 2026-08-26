<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成数据模型。
 *
 *   bin/kode make:model User
 *   bin/kode make:model Post --force
 */
#[AsCommand(
    name: 'make:model',
    description: '生成数据模型（app/models）',
    usage: 'make:model {name} {--force}',
)]
final class MakeModelCommand extends Command
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
            $this->error('请提供模型名：make:model User');

            return 1;
        }

        $class = $this->studly($raw);
        $table = $this->snakePlural($class);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace app\models;

use Kode\Framework\Database\Model;

/**
 * {$class} 模型（由 make:model 生成）
 *
 * @property int \$id
 */
final class {$class} extends Model
{
    protected string \$table = '{$table}';

    protected array \$fillable = [
        // 'name', 'email',
    ];
}

PHP;

        $path = $this->path('app/models/' . $class . '.php');
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成模型：{$path}（表名推测为 `{$table}`）");

        return 0;
    }
}
