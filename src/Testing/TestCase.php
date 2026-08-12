<?php

declare(strict_types=1);

namespace Kode\Framework\Testing;

use Kode\Framework\Application;
use Kode\Http\App;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * 框架集成测试基类（企业级测试起点）
 *
 * 下游应用测试继承本类即可获得：
 *  - bootApp()：启动一个真实框架应用（容器 + 路由 + 中间件全链路）；
 *  - get()/post()/put()/delete()：发起真实 HTTP 请求并拿到 TestResponse；
 *  - 断言助手：assertStatus() / assertJson() / assertSee()。
 *
 * 用法：
 * <code>
 *   final class UserApiTest extends \Kode\Framework\Testing\TestCase
 *   {
 *       protected function setUp(): void
 *       {
 *           parent::setUp();
 *           $this->bootApp(__DIR__ . '/../'); // 项目根（含 app/ config/）
 *       }
 *
 *       public function test_list(): void
 *       {
 *           $this->get('/api/users')->assertStatus(200)->assertSee('id');
 *       }
 *   }
 * </code>
 *
 * 说明：请求会真实穿过全局中间件（含限流/异常/访问日志），因此可视为端到端冒烟。
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array<string, mixed> 已启动的应用实例缓存（每个测试类启动一次） */
    private static ?Application $app = null;

    /** 项目根目录（子类可覆盖；默认取框架/项目根）。 */
    protected string $basePath = '';

    /**
     * 启动（或复用）框架应用。
     *
     * @param string $basePath 项目根（含 app/ config/ 的目录）。空则取 getcwd()。
     */
    protected function bootApp(string $basePath = ''): Application
    {
        if (self::$app !== null) {
            return self::$app;
        }

        $root = $basePath !== '' ? rtrim($basePath, '/') : getcwd();
        self::$app = Application::make($root);

        return self::$app;
    }

    /**
     * 当前已启动的 Application（未启动则先以 CWD 启动）。
     */
    protected function app(): Application
    {
        return self::$app ?? $this->bootApp($this->basePath);
    }

    /**
     * 取底层 HTTP App（已注册到容器）。
     */
    private function httpApp(): App
    {
        return resolve(App::class);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, '', $headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->jsonRequest('POST', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->jsonRequest('PUT', $uri, $data, $headers);
    }

    public function delete(string $uri, array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, '', $headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function jsonRequest(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['Content-Type'] = 'application/json';

        return $this->request($method, $uri, (string) json_encode($data), $headers);
    }

    private function request(string $method, string $uri, string $body = '', array $headers = []): TestResponse
    {
        $this->app();
        $request = new ServerRequest($method, $uri, $headers, $body);
        $response = $this->httpApp()->handle($request);

        return new TestResponse($response, $this);
    }

    /**
     * 测试后清理应用单例，避免用例间串扰。
     */
    protected function tearDown(): void
    {
        self::$app = null;
        parent::tearDown();
    }
}

/**
 * 测试响应包装（只读断言助手）。
 */
final class TestResponse
{
    /**
     * @param ResponseInterface $response
     * @param TestCase           $test 用于转发 PHPUnit 断言（self::assertSame 在静态上下文中指向本类会失败）
     */
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly TestCase $test,
    ) {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * 取响应头（单行，逗号拼接多值时取首个）。
     */
    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * 解析响应体为数组（非 JSON 时回退为空数组）。
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function assertStatus(int $expected): self
    {
        $actual = $this->status();
        $this->test->assertSame(
            $expected,
            $actual,
            "期望状态码 {$expected}，实际 {$actual}；响应体：{$this->body()}"
        );

        return $this;
    }

    /**
     * 响应体（原始或 JSON 字符串化后）包含给定子串。
     */
    public function assertSee(string $needle): self
    {
        $this->test->assertStringContainsString($needle, $this->body(), "响应体未包含：{$needle}");

        return $this;
    }
}
