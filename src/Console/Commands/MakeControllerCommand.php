<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成 HTTP 控制器。
 *
 *   kode make:controller User
 *   kode make:controller UserController --force
 */
#[AsCommand(
    name: 'make:controller',
    description: '生成 HTTP 控制器（app/controllers，--app 可定向子应用）',
    usage: 'make:controller {name : 生成的类名} {--force : 覆盖已存在的文件} {--app= : 子应用名，生成到 app/<app>/controllers}',
)]
final class MakeControllerCommand extends Command
{
    use GeneratesFiles;

    /** 本命令认识的选项 */
    private const OPTS = ['force', 'app'];

    public function __construct(string $basePath = '')
    {
        parent::__construct();
        $this->basePath = $basePath;
    }

    #[\Override]
    protected function handle(): int
    {
        if (($bad = $this->rejectUnknownOptions(self::OPTS)) !== null) {
            return $bad;
        }

        $raw = (string) $this->arg(0, '');
        if ($raw === '') {
            $this->error('请提供控制器名：make:controller User');

            return 1;
        }

        $class = $this->studly($raw);
        if (!str_ends_with($class, 'Controller')) {
            $class .= 'Controller';
        }

        $app = $this->resolveTargetApp();
        if ($app === null) {
            return 1;
        }

        $ns = 'app' . $this->appNamespaceSegment($app) . '\controllers';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

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

        $path = $this->path('app' . $this->appDirSegment($app) . '/controllers/' . $class . '.php');
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成控制器：{$path}");

        return 0;
    }
}
