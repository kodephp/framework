<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成 HTTP 控制器。
 *
 *   bin/kode make:controller User
 *   bin/kode make:controller UserController --force
 */
#[AsCommand(
    name: 'make:controller',
    description: '生成 HTTP 控制器（app/http/controllers）',
    usage: 'make:controller {name} {--force}',
)]
final class MakeControllerCommand extends Command
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
            $this->error('请提供控制器名：make:controller User');

            return 1;
        }

        $class = $this->studly($raw);
        if (!str_ends_with($class, 'Controller')) {
            $class .= 'Controller';
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace app\http\controllers;

use Kode\Framework\Http\Controller;

/**
 * {$class}（由 make:controller 生成）
 */
final class {$class} extends Controller
{
    public function index(\$req)
    {
        return \$this->json([
            'controller' => '{$class}',
        ]);
    }
}

PHP;

        $path = $this->path('app/http/controllers/' . $class . '.php');
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成控制器：{$path}");

        return 0;
    }
}
