<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Application;
use Kode\Http\App;
use Kode\Session\Middleware\SessionMiddleware;
use Kode\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * 会话（P1）接线验证：
 *  - SessionServiceProvider 绑定 SessionManager 单例；
 *  - HttpServiceProvider 把 SessionMiddleware 接进 HTTP 中间件管道；
 *  - session() 助手在 CLI（无请求）下返回 null，不抛错。
 */
final class SessionProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (app() === null) {
            Application::make(\Kode\Framework\Tests\TestCase::SKELETON_ROOT);
        }
    }

    public function testSessionManagerIsBound(): void
    {
        self::assertInstanceOf(SessionManager::class, app()->container->get(SessionManager::class));
    }

    public function testSessionMiddlewareRegisteredInPipeline(): void
    {
        /** @var App $http */
        $http = app()->container->get(App::class);
        $dispatcher = $http->getDispatcher();

        $ref = new \ReflectionProperty($dispatcher, 'middlewares');
        $ref->setAccessible(true);
        $middlewares = $ref->getValue($dispatcher);

        $registered = false;
        foreach ($middlewares as $middleware) {
            if ($middleware instanceof SessionMiddleware) {
                $registered = true;
                break;
            }
        }

        self::assertTrue($registered, 'SessionMiddleware 应已注册进 HTTP 中间件管道');
    }

    public function testSessionHelperReturnsNullWithoutRequest(): void
    {
        // CLI 环境无请求：重置会话单例，模拟干净上下文；助手应返回 null（不抛错）。
        // （SessionManager 是容器单例，可能被其它 HTTP 测试写入残留会话，故此处显式清空。）
        $manager = app()->container->get(SessionManager::class);
        $ref = new \ReflectionProperty($manager, 'session');
        $ref->setAccessible(true);
        $ref->setValue($manager, null);

        self::assertNull(session());
    }

    public function testConfigDefaults(): void
    {
        self::assertTrue((bool) config('session.enabled'));
        self::assertSame('file', config('session.default'));
    }
}
