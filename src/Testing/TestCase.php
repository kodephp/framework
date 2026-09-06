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
 *    跨测试类配置不同时置 `protected bool $independentApp = true`（默认复用首个实例）；
 *  - get()/post()/put()/patch()/delete()/jsonRequest()：发起真实 HTTP 请求并拿到 TestResponse；
 *  - 断言助手：assertStatus() / assertJson() / assertJsonPath() / assertHeader() / assertSee()。
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
     * 启动期配置覆盖（最高优先级，透传给 Application::make 的 configOverrides）。
     * 子类可设置以覆写 config/*.php 的键（如 ['apidoc' => ['enabled' => true]]）。
     *
     * @var array<string, mixed>
     */
    protected array $configOverrides = [];

    /**
     * 是否要求「独立的进程级应用实例」（默认 false：跨测试复用首次 boot 的应用，
     * 保留 static 注册型路由惯例——如 FrameworkSmoke 用 static 标记只注册一次路由）。
     *
     * 需要不同于既有实例的配置时置 true（如 apidoc 显式开/关），boot 前会重建 kode/core 单例。
     */
    protected bool $independentApp = false;

    /**
     * 启动（或复用）框架应用。
     *
     * @param string $basePath 项目根（含 app/ config/ 的目录）。空则取 getcwd()。
     */
    protected function bootApp(string $basePath = ''): Application
    {
        if ($this->independentApp) {
            $this->resetCoreAppSingleton();
        }

        if (self::$app !== null) {
            return self::$app;
        }

        $root = $basePath !== '' ? rtrim($basePath, '/') : getcwd();
        self::$app = Application::make($root, $this->configOverrides);

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
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->jsonRequest('PATCH', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function jsonRequest(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        $headers['Content-Type'] = 'application/json';

        return $this->request($method, $uri, (string) json_encode($data), $headers, $data);
    }

    private function request(string $method, string $uri, string $body = '', array $headers = [], ?array $parsedBody = null): TestResponse
    {
        $this->app();
        $request = new ServerRequest($method, $uri, $headers, $body);
        if ($parsedBody !== null) {
            // JSON 体同步解析后形态：真实服务端由 LazyServerRequest 按需解析，
            // 测试内显式预置，避免控制器 getParsedBody() 读到 null。
            $request = $request->withParsedBody($parsedBody);
        }
        // Cookie 头 → cookieParams：PSR-7 不会从头自动解析 Cookie，而会话等组件
        // 消费的是 getCookieParams()；此处显式桥接以贴合真实 HTTP 语义。
        $cookieHeader = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Cookie') === 0) {
                $cookieHeader = is_array($value) ? (string) reset($value) : (string) $value;
                break;
            }
        }
        if ($cookieHeader !== '') {
            $cookies = [];
            foreach (explode(';', $cookieHeader) as $pair) {
                $eq = strpos($pair, '=');
                if ($eq === false) {
                    continue;
                }
                $k = trim(substr($pair, 0, $eq));
                if ($k !== '') {
                    $cookies[$k] = trim(substr($pair, $eq + 1));
                }
            }
            $request = $request->withCookieParams($cookies);
        }
        $response = $this->httpApp()->handle($request);

        return new TestResponse($response, $this);
    }

    /**
     * 测试后清理应用单例，避免用例间串扰。
     *
     * 同时重置 kode/core 的进程级 App 单例（CoreApp::boot() 重复调用会直接返回
     * 首个实例，导致后启动测试的 config overrides / providers 无法生效），
     * 保证每个测试类都拿到独立、按自身配置引导的应用。
     */
    protected function tearDown(): void
    {
        self::$app = null;

        if ($this->independentApp) {
            $this->resetCoreAppSingleton();
        }

        parent::tearDown();
    }

    /**
     * 重置 kode/core 进程级 App 单例（供要求独立引导的测试类使用）。
     */
    private function resetCoreAppSingleton(): void
    {
        $coreApp = new \ReflectionClass(\Kode\Core\App::class);
        $coreApp->getProperty('instance')->setValue(null, null);
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

    /**
     * 响应体为合法 JSON（且可选断言顶层子集相等）。
     *
     * @param array<string, mixed> $subset 非空时断言解码后数组包含该子集
     */
    public function assertJson(array $subset = []): self
    {
        $decoded = json_decode($this->body(), true);
        $this->test->assertIsArray($decoded, '响应体不是合法 JSON：' . $this->body());
        foreach ($subset as $key => $value) {
            $this->test->assertArrayHasKey($key, $decoded);
            $this->test->assertEquals($value, $decoded[$key]);
        }

        return $this;
    }

    /**
     * JSON 点路径断言（`data.user.name`，数字段按数组下标）。
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        $node = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }
            $this->test->fail("JSON 路径不存在：{$path}；响应体：" . $this->body());
        }
        $this->test->assertEquals($expected, $node, "JSON 路径 {$path} 不符预期");

        return $this;
    }

    /**
     * 响应头断言（单行值比较）。
     */
    public function assertHeader(string $name, string $expected): self
    {
        $this->test->assertSame($expected, $this->header($name), "响应头 {$name} 不符预期");

        return $this;
    }
}
