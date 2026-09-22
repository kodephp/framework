<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\DI\Container;
use Kode\Exception\ExceptionManager;
use Kode\Framework\Providers\ExceptionServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * 生产模式对外提示语的接线验证。
 *
 * 此前 config/http.php 的 `production_message`（与 .env 的 HTTP_PRODUCTION_MESSAGE）无人读取：
 * 改了文案，生产环境仍回显 kode/exception 的内置默认，属于「配置说 X、运行时做 Y」。
 */
final class ExceptionProductionMessageTest extends TestCase
{
    private function formatMessage(?string $configured): string
    {
        $config = new Config();
        $config->set('app.debug', false);
        if ($configured !== null) {
            $config->set('http.production_message', $configured);
        }

        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(LoggerInterface::class, new NullLogger());

        (new ExceptionServiceProvider($container))->register();

        /** @var array{msg: string} $body */
        $body = $container->get(ExceptionManager::class)
            ->format(new RuntimeException('secret internal path /var/www/html'));

        return $body['msg'];
    }

    public function testProductionMessageComesFromConfig(): void
    {
        self::assertSame('服务正在维护中', $this->formatMessage('服务正在维护中'));
    }

    public function testBlankProductionMessageKeepsPackageDefault(): void
    {
        // 空值 = 不覆盖：文案单一事实源留在 kode/exception，避免两处硬编码漂移。
        self::assertSame('系统繁忙，请稍后重试', $this->formatMessage('   '));
    }

    public function testMissingProductionMessageKeepsPackageDefault(): void
    {
        self::assertSame('系统繁忙，请稍后重试', $this->formatMessage(null));
    }
}
