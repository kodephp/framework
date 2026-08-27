<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Exception\ExceptionManager;
use Kode\Framework\Application;
use Kode\Framework\Http\Middleware\ExceptionMiddleware;
use Kode\Framework\Support\EnvLoader;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 框架健壮性测试：错误处理器防御、.env 解析、容器未启动守卫、启动失败清晰报错。
 */
final class RobustnessTest extends TestCase
{
    // ----------------------------------------------------------------
    // 1) ExceptionMiddleware：错误渲染器自身失败时绝不裸 500
    // ----------------------------------------------------------------

    public function testFallsBackToSafe500WhenRendererThrows(): void
    {
        // 模拟 kode/exception 的 respond() 自身抛错（循环依赖 / 内部异常）。
        $manager = $this->createMock(ExceptionManager::class);
        $manager->method('respond')->willThrowException(new \RuntimeException('renderer broke'));

        $mw = new ExceptionMiddleware($manager);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('business error');
            }
        };

        $resp = $mw->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('Internal Server Error', $body['message']);
        // 回退响应仍带 trace_id 字段（可能为空的字符串，但结构完整）。
        self::assertArrayHasKey('trace_id', $body);
    }

    public function testHappyPathStillPassesThrough(): void
    {
        $manager = $this->createMock(ExceptionManager::class);
        $mw = new ExceptionMiddleware($manager);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return \Kode\Http\Response::make('ok', 200);
            }
        };

        $resp = $mw->process(new ServerRequest('GET', '/'), $handler);
        self::assertSame(200, $resp->getStatusCode());
    }

    // ----------------------------------------------------------------
    // 2) .env 解析健壮性
    // ----------------------------------------------------------------

    public function testEnvParsePlain(): void
    {
        self::assertSame(['APP_NAME', 'kode'], EnvLoader::parseLine('APP_NAME=kode'));
    }

    public function testEnvParseExportPrefix(): void
    {
        self::assertSame(['APP_NAME', 'kode'], EnvLoader::parseLine('export APP_NAME=kode'));
    }

    public function testEnvParseQuoted(): void
    {
        self::assertSame(['DSN', 'host=localhost db=test'], EnvLoader::parseLine('DSN="host=localhost db=test"'));
        self::assertSame(['DSN', 'value with spaces'], EnvLoader::parseLine("DSN='value with spaces'"));
    }

    public function testEnvParseInlineComment(): void
    {
        self::assertSame(['APP_ENV', 'local'], EnvLoader::parseLine('APP_ENV=local # 本地环境'));
        // 值中的 # 前无空白 → 视为值的一部分，不应被剥离。
        self::assertSame(['REDIS', 'redis://127.0.0.1:6379#db0'], EnvLoader::parseLine('REDIS=redis://127.0.0.1:6379#db0'));
    }

    public function testEnvParseHashInsideQuotesIsNotComment(): void
    {
        // v0.8.52 回归：引号内的 # 不得视为注释——旧正则先剥注释再剥引号，
        // `KEY="abc # def"` 被截成 `KEY="abc`，最终值带脏引号。
        self::assertSame(['PROMPT', 'abc # def'], EnvLoader::parseLine('PROMPT="abc # def"'));
        self::assertSame(['PROMPT', "ab # cd"], EnvLoader::parseLine("PROMPT='ab # cd'"));
        // 引号外的「空格+#」仍是注释起点
        self::assertSame(['K', 'a#b'], EnvLoader::parseLine('K=a#b # tail'));
    }

    public function testEnvParseSkipsInvalid(): void
    {
        self::assertNull(EnvLoader::parseLine('# 纯注释'));
        self::assertNull(EnvLoader::parseLine(''));
        self::assertNull(EnvLoader::parseLine('NO_EQUALS'));
        self::assertNull(EnvLoader::parseLine('=no_key'));
    }

    public function testEnvParseEmptyValue(): void
    {
        self::assertSame(['FLAG', ''], EnvLoader::parseLine('FLAG='));
    }

    public function testEnvLoadWritesAndSkipsExisting(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kode_env_');
        file_put_contents($file, "A=1\nB=2\n");

        // 预先存在一个真实环境变量，load 不应覆盖它。
        putenv('PRE=kept');
        $_ENV['PRE'] = 'kept';
        $_SERVER['PRE'] = 'kept';

        EnvLoader::load($file);

        self::assertSame('1', ($_ENV['A'] ?? null));
        self::assertSame('2', ($_SERVER['B'] ?? null));
        self::assertSame('kept', ($_ENV['PRE'] ?? null));

        // BOM：首行带 BOM 时 key 不应残留乱码前缀。
        $bomFile = tempnam(sys_get_temp_dir(), 'kode_env_bom_');
        file_put_contents($bomFile, "\xEF\xBB\xBF" . "BOMKEY=ok\n");
        EnvLoader::load($bomFile);
        self::assertSame('ok', ($_ENV['BOMKEY'] ?? null));

        unlink($file);
        unlink($bomFile);
    }

    // ----------------------------------------------------------------
    // 3) 容器未启动：resolve() 守卫
    // ----------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testResolveThrowsWhenContainerNotBooted(): void
    {
        // 独立进程确保无任何 Application 已启动。
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('服务容器尚未启动');

        // 直接调用框架助手（已通过 autoload 载入）。
        $fn = \Closure::fromCallable('resolve');
        $fn('cache');
    }

    // ----------------------------------------------------------------
    // 4) 启动失败：清晰报错且保留原始原因
    // ----------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testBootstrapFailureWrapsWithContext(): void
    {
        $dir = sys_get_temp_dir() . '/kode_boot_' . uniqid();
        mkdir($dir . '/config', 0o755, true);
        // 指向一个不存在的 provider 类：CoreApp::boot 实例化时必然失败，
        // 应被 Application 包成带上下文的 RuntimeException。
        file_put_contents($dir . '/config/app.php', "<?php return ['providers' => ['\app\\\\no\\\\such\\\\Provider']];");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('应用启动失败');

        Application::make($dir);
    }
}
