<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands;

use Kode\Console\Attribute\AsCommand;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\Concerns\GeneratesFiles;

/**
 * 生成 HTTP 中间件。
 *
 *   bin/kode make:middleware Auth
 *   bin/kode make:middleware Cors --force
 */
#[AsCommand(
    name: 'make:middleware',
    description: '生成 HTTP 中间件（app/http/middleware）',
    usage: 'make:middleware {name} {--force}',
)]
final class MakeMiddlewareCommand extends Command
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
            $this->error('请提供中间件名：make:middleware Auth');

            return 1;
        }

        $class = $this->studly($raw);
        if (!str_ends_with($class, 'Middleware')) {
            $class .= 'Middleware';
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace app\http\middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * {$class}（由 make:middleware 生成）
 */
final class {$class} implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
    {
        // 在下游之前处理请求……
        \$response = \$handler->handle(\$request);
        // 在响应返回前处理响应……

        return \$response;
    }
}

PHP;

        $path = $this->path('app/http/middleware/' . $class . '.php');
        if (!$this->writeFile($path, $content, $this->flag('force', false))) {
            $this->warn("已存在，跳过（用 --force 覆盖）：{$path}");

            return 0;
        }

        $this->success("已生成中间件：{$path}（记得在 config 或路由上注册）");

        return 0;
    }
}
