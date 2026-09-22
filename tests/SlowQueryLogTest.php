<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Database\Event\EventManager;
use Kode\Database\Event\SqlEvent;
use Kode\Framework\Database\SlowQueryLogger;
use Kode\Framework\Providers\DatabaseServiceProvider;
use Kode\Framework\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;

/**
 * 慢查询日志接线（kode/database >= 1.20 的 SqlEvent 订阅端）。
 *
 * 分两段：{@see SlowQueryLogger} 的筛选口径（阈值、失败、绝不落绑定参数），
 * 以及 DatabaseServiceProvider 的开关与「同进程只挂一次」。
 */
final class SlowQueryLogTest extends TestCase
{
    protected bool $independentApp = true;

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    private function observer(RecordingLogger $logger, float $threshold): SlowQueryLogger
    {
        return new SlowQueryLogger(static fn (): LoggerInterface => $logger, $threshold);
    }

    public function testBelowThresholdIsNotLogged(): void
    {
        $logger = $this->logger();
        ($this->observer($logger, 1.0))(new SqlEvent('SELECT 1', [], 'mysql', 0.2));

        self::assertSame([], $logger->records);
    }

    public function testSlowQueryLogsWarningWithContext(): void
    {
        $logger = $this->logger();
        ($this->observer($logger, 1.0))(new SqlEvent('SELECT * FROM kode_users', [], 'pgsql', 2.345678));

        $records = $logger->recordsAt('warning');
        self::assertCount(1, $records);
        self::assertSame('慢查询', $records[0]['message']);
        self::assertSame('pgsql', $records[0]['context']['connection']);
        self::assertSame(2.3457, $records[0]['context']['seconds']);
        self::assertSame('SELECT * FROM kode_users', $records[0]['context']['sql']);
        self::assertSame(1.0, $records[0]['context']['threshold']);
    }

    public function testBindingsNeverReachTheLog(): void
    {
        $logger = $this->logger();
        ($this->observer($logger, 1.0))(new SqlEvent(
            'SELECT * FROM kode_users WHERE phone = ?',
            ['13800138000'],
            'pgsql',
            3.0
        ));

        $context = $logger->recordsAt('warning')[0]['context'];
        self::assertArrayNotHasKey('bindings', $context);
        self::assertStringNotContainsString('13800138000', (string) json_encode($context, JSON_UNESCAPED_UNICODE));
    }

    public function testFailedQueryIsLoggedRegardlessOfDuration(): void
    {
        $logger = $this->logger();
        $error = new \RuntimeException('SQLSTATE[42P01] relation does not exist');
        ($this->observer($logger, 1.0))(new SqlEvent('SELECT * FROM missing', [], 'pgsql', 0.001, $error));

        $records = $logger->recordsAt('error');
        self::assertCount(1, $records);
        self::assertSame('SQL 执行失败', $records[0]['message']);
        self::assertSame($error->getMessage(), $records[0]['context']['error']);
        self::assertSame([], $logger->recordsAt('warning'));
    }

    public function testNonPositiveThresholdFallsBackToDefault(): void
    {
        $logger = $this->logger();
        // threshold=0 若原样生效等于「全量落盘」，属误配而非意图
        $observer = $this->observer($logger, 0.0);
        $observer(new SqlEvent('SELECT 1', [], 'mysql', 0.5));
        self::assertSame([], $logger->records);

        $observer(new SqlEvent('SELECT 1', [], 'mysql', 1.5));
        self::assertCount(1, $logger->recordsAt('warning'));
    }

    public function testMissingConnectionNameFallsBackToDefaultLabel(): void
    {
        $logger = $this->logger();
        ($this->observer($logger, 1.0))(new SqlEvent('SELECT 1', [], null, 2.0));

        self::assertSame('default', $logger->recordsAt('warning')[0]['context']['connection']);
    }

    public function testUnresolvableLoggerDoesNotThrow(): void
    {
        $observer = new SlowQueryLogger(static fn (): ?LoggerInterface => null, 1.0);
        $observer(new SqlEvent('SELECT 1', [], 'mysql', 9.0));

        $this->expectNotToPerformAssertions();
    }

    public function testDisabledConfigRegistersNoListener(): void
    {
        $before = EventManager::getInstance()->getListeners(SqlEvent::class);
        $this->configOverrides = ['database' => ['slow_log' => ['enabled' => false]]];
        $this->bootApp();

        self::assertCount(count($before), EventManager::getInstance()->getListeners(SqlEvent::class));
    }

    public function testStringFalseFromEnvIsTreatedAsDisabled(): void
    {
        // 配置常来自 env()，'false' 字符串在 PHP 里是真值——必须按语义判假
        $before = EventManager::getInstance()->getListeners(SqlEvent::class);
        $this->configOverrides = ['database' => ['slow_log' => ['enabled' => 'false']]];
        $this->bootApp();

        self::assertCount(count($before), EventManager::getInstance()->getListeners(SqlEvent::class));
    }

    public function testEnabledConfigRegistersListenerOnce(): void
    {
        $before = count(EventManager::getInstance()->getListeners(SqlEvent::class));
        $this->configOverrides = ['database' => ['slow_log' => [
            'enabled' => true,
            'threshold' => 0.5,
        ]]];
        $app = $this->bootApp();

        $after = EventManager::getInstance()->getListeners(SqlEvent::class);
        self::assertCount($before + 1, $after);

        $listener = end($after);
        self::assertInstanceOf(SlowQueryLogger::class, $listener);
        self::assertSame(0.5, (new \ReflectionProperty(SlowQueryLogger::class, 'threshold'))->getValue($listener));

        // 同进程二次引导（热重载 / 后续测试类）不得重复挂载，否则一条慢查询记两遍
        (new DatabaseServiceProvider($app->core()->container))->register();
        self::assertCount($before + 1, EventManager::getInstance()->getListeners(SqlEvent::class));
    }
}
