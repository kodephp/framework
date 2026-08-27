<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Testing\TestCase;
use Kode\Http\App;
use Kode\Session\SessionManager;

/**
 * 惰性会话中间件（v0.8.23 性能修复）集成测试。
 *
 * 验证：
 *  - /ping 这类不碰会话的请求：全程零会话文件 I/O、不下发 Set-Cookie、会话未启动；
 *  - 用到会话的请求：session()->set 落盘并下发 Set-Cookie，且可被携带该 Cookie 的后续请求读回；
 *  - 中间件仍 instanceof vendor SessionMiddleware（兼容既有接线校验）。
 */
final class LazySessionMiddlewareTest extends TestCase
{
    private static bool $routesRegistered = false;

    private static function sessionsDir(): string
    {
        /** @var SessionManager $manager */
        $manager = resolve(SessionManager::class);

        return (string) ($manager->getConfig('drivers')['file']['path'] ?? sys_get_temp_dir());
    }

    private static function countSessionFiles(): int
    {
        $dir = self::sessionsDir();
        if (!is_dir($dir)) {
            return 0;
        }

        return count(glob($dir . '/*.php') ?: []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 会话目录隔离到临时路径：storage/sessions 为持久目录，跨套件运行会累积
        // 过期文件，而 GC（gc_probability=1/100）可能在断言窗口内清理它们，
        // 造成「写会话应产生会话文件」的计数断言偶发失败。
        $this->configOverrides['session'] = [
            ...($this->configOverrides['session'] ?? []),
            'enabled' => true,
            'drivers' => [
                'file' => ['path' => sys_get_temp_dir() . '/kode-session-test-' . getmypid()],
            ],
        ];
        $this->bootApp();

        if (!self::$routesRegistered) {
            /** @var App $app */
            $app = resolve(App::class);
            $app->get('/__session_write', static function () {
                session()->set('foo', 'bar');

                return \Kode\Http\Response::make('', 204);
            });
            $app->get('/__session_read', static function () {
                return \Kode\Http\Response::make((string) session()->get('foo', ''), 200);
            });
            self::$routesRegistered = true;
        }
    }

    public function testPingDoesNotTouchSession(): void
    {
        $before = self::countSessionFiles();

        $ping = $this->get('/ping');
        $ping->assertStatus(200);

        // 零会话文件 I/O
        self::assertSame($before, self::countSessionFiles(), '/ping 不应产生任何会话文件');
        // 不下发 Set-Cookie
        self::assertSame('', $ping->header('Set-Cookie'), '/ping 不应下发 Set-Cookie');

        // 管理器上的会话对象未被启动（惰性：仅创建未启动对象）
        /** @var SessionManager $manager */
        $manager = resolve(SessionManager::class);
        $session = $manager->getSession();
        self::assertNotNull($session);
        self::assertFalse($session->isStarted(), '/ping 不应启动会话');
    }

    public function testUsedSessionPersistsAndReadsBack(): void
    {
        $before = self::countSessionFiles();

        $write = $this->get('/__session_write');
        self::assertSame(204, $write->status());

        // 用到了会话 → 落盘 + 下发 Set-Cookie
        self::assertGreaterThan($before, self::countSessionFiles(), '写会话应产生会话文件');
        $cookie = $write->header('Set-Cookie');
        self::assertNotSame('', $cookie, '写会话应下发 Set-Cookie');

        // 解析 Cookie 中的会话 ID
        $id = $this->parseSessionId($cookie);
        self::assertNotEmpty($id);

        // 携带该会话 ID 的后续请求可读回数据（经 Cookie 头载体，与真实运行时
        // getCookieParams 解析一致；v0.8.52 起 ID 来源默认仅 cookie，
        // query/body/header 载体需 config session.id_sources 显式开启）。
        $read = $this->get('/__session_read', ['Cookie' => 'KODE_SESSION=' . urlencode($id)]);
        self::assertSame('bar', $read->body());
    }

    public function testMiddlewareIsRegisteredAsVendorSessionMiddleware(): void
    {
        /** @var App $http */
        $http = resolve(App::class);
        $dispatcher = $http->getDispatcher();

        $ref = new \ReflectionProperty($dispatcher, 'middlewares');
        $ref->setAccessible(true);
        $middlewares = $ref->getValue($dispatcher);

        $found = false;
        foreach ($middlewares as $mw) {
            if ($mw instanceof \Kode\Session\Middleware\SessionMiddleware) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'LazySessionMiddleware 应已注册（且 instanceof vendor SessionMiddleware）');
    }

    private function parseSessionId(string $setCookie): string
    {
        // Set-Cookie: KODE_SESSION=xxxx; Path=...
        if (preg_match('/KODE_SESSION=([^;,\s]+)/', $setCookie, $m)) {
            return $m[1];
        }

        return '';
    }
}
