<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Console\Attribute\AsCommand;
use Kode\Console\Input;
use Kode\Console\Output;
use Kode\Console\Signature;
use Kode\Framework\Console\Command;
use Kode\Framework\Console\Commands\DbSeedCommand;
use Kode\Framework\Console\Commands\MakeCommandCommand;
use Kode\Framework\Console\Commands\MakeControllerCommand;
use Kode\Framework\Console\Commands\MakeMigrationCommand;
use Kode\Framework\Console\Commands\MakeModelCommand;
use Kode\Framework\Console\Commands\MakeMiddlewareCommand;
use PHPUnit\Framework\TestCase;

/**
 * make:* 脚手架命令测试：验证生成的文件存在、内容正确且能通过 php -l 语法检查。
 */
final class MakeCommandsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/kode_make_' . uniqid('', true);
        mkdir($this->tmp, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function execute(Command $cmd, array $argv): int
    {
        return $cmd->fire($this->inputFor($cmd, $argv), new Output(fopen('php://memory', 'w')));
    }

    /**
     * 构造带命令名前缀 + Signature 的 Input（模拟 bin/kode 的真实派发方式）。
     */
    private function inputFor(Command $cmd, array $argv): Input
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

    private function assertGenerated(string $path, string $needle): void
    {
        $full = $this->tmp . '/' . ltrim($path, '/');
        self::assertFileExists($full, "期望生成文件：{$path}");
        self::assertStringContainsString($needle, file_get_contents($full));
        $result = shell_exec('php -l ' . escapeshellarg($full) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $result, "生成文件语法错误：{$path}");
    }

    public function testMakeController(): void
    {
        $code = $this->execute(new MakeControllerCommand($this->tmp), ['User']);
        self::assertSame(0, $code);
        $this->assertGenerated('app/Http/Controllers/UserController.php', 'class UserController extends Controller');
        $this->assertGenerated('app/Http/Controllers/UserController.php', 'namespace App\Http\Controllers;');
    }

    public function testMakeControllerKeepsSuffix(): void
    {
        $this->execute(new MakeControllerCommand($this->tmp), ['UserController']);
        $this->assertGenerated('app/Http/Controllers/UserController.php', 'class UserController extends Controller');
    }

    public function testMakeModel(): void
    {
        $this->execute(new MakeModelCommand($this->tmp), ['Post']);
        $this->assertGenerated('app/Models/Post.php', 'class Post extends Model');
        $this->assertGenerated('app/Models/Post.php', "protected string \$table = 'posts';");
    }

    public function testMakeMigration(): void
    {
        $this->execute(new MakeMigrationCommand($this->tmp), ['create_posts_table']);
        $files = glob($this->tmp . '/database/migrations/*_create_posts_table.php') ?: [];
        self::assertCount(1, $files, '应生成一份时间戳迁移文件');
        self::assertStringContainsString('class CreatePostsTable extends Migration', file_get_contents($files[0]));
    }

    public function testMakeMiddleware(): void
    {
        $this->execute(new MakeMiddlewareCommand($this->tmp), ['Auth']);
        $this->assertGenerated('app/Http/Middleware/AuthMiddleware.php', 'class AuthMiddleware implements MiddlewareInterface');
    }

    public function testMakeCommand(): void
    {
        $this->execute(new MakeCommandCommand($this->tmp), ['SendNewsletter']);
        $this->assertGenerated('app/Console/Commands/SendNewsletterCommand.php', 'class SendNewsletterCommand extends Command');
        $this->assertGenerated('app/Console/Commands/SendNewsletterCommand.php', "name: 'send_newsletter'");
    }

    public function testSkipWhenExistsWithoutForce(): void
    {
        $this->execute(new MakeControllerCommand($this->tmp), ['User']);
        // 第二次不加 --force：文件已存在，应跳过（返回 0 而非报错）。
        $code = $this->execute(new MakeControllerCommand($this->tmp), ['User']);
        self::assertSame(0, $code);
    }

    public function testDbSeedMissingClassReportsError(): void
    {
        $code = $this->execute(new DbSeedCommand($this->tmp), ['--class=NoSuchSeeder']);
        self::assertSame(1, $code);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
