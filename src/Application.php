<?php

declare(strict_types=1);

namespace Kode\Framework;

use Kode\Console\Kernel as ConsoleKernel;
use Kode\Core\App as CoreApp;
use Kode\Http\App as HttpApp;
use Kode\Framework\Providers\CacheServiceProvider;
use Kode\Framework\Providers\ConfigCenterServiceProvider;
use Kode\Framework\Providers\ConfigServiceProvider;
use Kode\Framework\Providers\ConsoleServiceProvider;
use Kode\Framework\Providers\DatabaseServiceProvider;
use Kode\Framework\Providers\ExceptionServiceProvider;
use Kode\Framework\Providers\EventServiceProvider;
use Kode\Framework\Providers\HttpServiceProvider;
use Kode\Framework\Providers\JwtServiceProvider;
use Kode\Framework\Providers\LifecycleServiceProvider;
use Kode\Framework\Providers\ApiDocServiceProvider;
use Kode\Framework\Providers\ComplianceServiceProvider;
use Kode\Framework\Providers\ObservabilityServiceProvider;
use Kode\Framework\Providers\LimitingServiceProvider;
use Kode\Framework\Providers\LogServiceProvider;
use Kode\Framework\Providers\MessagingServiceProvider;
use Kode\Framework\Providers\PluginServiceProvider;
use Kode\Framework\Providers\ResilienceServiceProvider;
use Kode\Framework\Providers\SchedulingServiceProvider;
use Kode\Framework\Providers\SessionServiceProvider;
use Kode\Framework\Providers\AopServiceProvider;
use Kode\Framework\Providers\ParallelServiceProvider;
use Kode\Framework\Providers\TenantServiceProvider;
use Kode\Framework\Providers\TenantStorageServiceProvider;
use Kode\Framework\Providers\TranslationServiceProvider;
use Kode\Framework\Providers\GracefulShutdownServiceProvider;
use Kode\Framework\Providers\HealthServiceProvider;
use Kode\Framework\Providers\FeatureServiceProvider;
use Kode\Framework\Providers\LockServiceProvider;
use Kode\Framework\Providers\IdempotencyServiceProvider;
use Kode\Framework\Providers\ServiceDiscoveryServiceProvider;
use Kode\Framework\Providers\TracerServiceProvider;
use Kode\Framework\Providers\HttpClientServiceProvider;
use Kode\Framework\Providers\QueueServiceProvider;
use Kode\Framework\Providers\SnowflakeServiceProvider;
use Kode\Framework\Providers\ProcessServiceProvider;
use Kode\Framework\Providers\ValidationServiceProvider;

/**
 * Kode Framework 应用外壳
 *
 * 真正的内核是 kode/core 的 App::boot()（加载配置 → 注册 ServiceProvider →
 * 启动运行时）。本类只是框架层的一层薄封装，负责：加载 .env、预置 path.base、
 * 收集 providers/runtime、在启动后加载 app/bootstrap.php，并对外暴露
 * http()/console() 等便捷方法，使示例应用保持简洁写法。
 */
final class Application
{
    /**
     * 框架版本（与 composer.json 保持一致；用于 /health 探针与日志）。
     */
    public const VERSION = '0.8.53';

    /**
     * 能力 → 期望 ServiceProvider 映射（用于启动自检）。
     *
     * 若某个 kode/* 包已安装，却没把对应 Provider 接入 providers 列表（硬编码 $defaults
     * 或 config/app.php），启动自检会明确告警，避免「装了包却静默失接、能力不可用」的哑火。
     *
     * @var array<string, class-string|string>
     */
    private const CAPABILITY_PROVIDERS = [
        'kode/cache'      => CacheServiceProvider::class,
        'kode/database'   => DatabaseServiceProvider::class,
        'kode/queue'      => QueueServiceProvider::class,
        'kode/messaging'  => MessagingServiceProvider::class,
        'kode/scheduling' => SchedulingServiceProvider::class,
        'kode/session'    => SessionServiceProvider::class,
        'kode/aop'        => AopServiceProvider::class,
        'kode/parallel'   => ParallelServiceProvider::class,
    ];

    private static ?Application $instance = null;

    private CoreApp $app;

    private readonly string $basePath;

    private bool $booted = false;

    /**
     * preloadAppConfig() 的调用缓存（v0.8.42，M2）：config/app.php 引导期至多 require 一次，
     * 避免 providers()/runtimeModes()/checkProviderCoverage() 重复 require 触发顶层副作用
     * （define() 重复定义告警等）。null = 尚未加载。
     *
     * @var array<string, mixed>|null
     */
    private ?array $preloaded = null;

    public static function getInstance(): ?Application
    {
        return self::$instance;
    }

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * 便捷启动入口。
     *
     * @param array<string, mixed> $configOverrides 启动期配置覆盖（最高优先级，
     *                                               用于测试覆写 config/*.php 的键；生产可留空）
     */
    public static function make(string $basePath, array $configOverrides = []): static
    {
        return (new static($basePath))->bootstrap($configOverrides);
    }

    public function bootstrap(array $configOverrides = []): static
    {
        // 提前暴露外壳实例：让 app/bootstrap.php 等能取到本实例。
        self::$instance = $this;

        $this->loadEnvironment();

        try {
            $this->app = CoreApp::boot([
                'base_path'   => $this->basePath,
                'config_path' => $this->basePath . '/config',
                'providers'   => $this->providers(),
                'runtime'     => $this->runtimeModes(),
                // path.base 必须在启动期可用（部分 provider 在 boot 阶段就要拼路径），
                // 故通过 boot 的内联配置预置。注意 core\Config 的 get() 按点号遍历
                // 嵌套数组，因此此处必须用嵌套结构（而非字面键 'path.base'）。
                'config'      => [
                    'path' => ['base' => $this->basePath],
                    ...$configOverrides,
                ],
            ]);
        } catch (\Throwable $e) {
            // 回滚外壳单例：boot 失败后 getInstance() 不得返回半初始化实例，
            // 否则后续依赖它的代码会拿到「看似可用实则未 booted」的外壳。
            if (self::$instance === $this) {
                self::$instance = null;
            }

            throw new \RuntimeException(
                '应用启动失败（' . $this->basePath . '）：' . $e->getMessage(),
                0,
                $e
            );
        }

        // 用户引导：AOP 切面、事件监听等（此时 App 已就绪，门面/助手均可用）。
        $this->loadUserBootstrap();

        // 启动自检：已安装包但未接线对应 Provider 时明确告警（不再静默失接）。
        $this->checkProviderCoverage();

        $this->booted = true;

        return $this;
    }

    public function core(): CoreApp
    {
        return $this->app;
    }

    public function basePath(string $path = ''): string
    {
        return $this->app->basePath($path);
    }

    public function config(): \Kode\Core\Config\Config
    {
        return $this->app->config;
    }

    /**
     * 从容器解析服务（支持构造函数自动装配与属性注入）。
     *
     * @return mixed
     */
    public function makeService(string $id, array $parameters = [])
    {
        return $this->app->make($id, $parameters);
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function http(): HttpApp
    {
        return $this->app->make(HttpApp::class);
    }

    public function console(): ConsoleKernel
    {
        return $this->app->make(ConsoleKernel::class);
    }

    // ------------------------------------------------------------------
    // 内部引导
    // ------------------------------------------------------------------

    /**
     * 收集要注册的服务提供者：框架内置 + config/app.php 里的用户扩展。
     *
     * @return array<int, class-string|object>
     */
    private function providers(): array
    {
        $defaults = [
            ConfigCenterServiceProvider::class,
            ConfigServiceProvider::class,
            LogServiceProvider::class,
            ExceptionServiceProvider::class,
            CacheServiceProvider::class,
            EventServiceProvider::class,
            JwtServiceProvider::class,
            ValidationServiceProvider::class,
            LimitingServiceProvider::class,
            DatabaseServiceProvider::class,
            QueueServiceProvider::class,
            HttpClientServiceProvider::class,
            MessagingServiceProvider::class,
            ResilienceServiceProvider::class,
            TranslationServiceProvider::class,
            HttpServiceProvider::class,
            ObservabilityServiceProvider::class,
            LifecycleServiceProvider::class,
            ComplianceServiceProvider::class,
            ApiDocServiceProvider::class,
            PluginServiceProvider::class,
            SnowflakeServiceProvider::class,
            ProcessServiceProvider::class,
            SchedulingServiceProvider::class,
            SessionServiceProvider::class,
            AopServiceProvider::class,
            ParallelServiceProvider::class,
            TenantServiceProvider::class,
            TenantStorageServiceProvider::class,
            GracefulShutdownServiceProvider::class,
            FeatureServiceProvider::class,
            ServiceDiscoveryServiceProvider::class,
            TracerServiceProvider::class,
            HealthServiceProvider::class,
            LockServiceProvider::class,
            IdempotencyServiceProvider::class,
            ConsoleServiceProvider::class,
        ];

        $user = $this->preloadAppConfig()['providers'] ?? [];
        $user = is_array($user) ? $user : [];

        // SORT_REGULAR：providers 允许对象实例（见上方 docblock），默认的字符串比较
        // 会对无 __toString 的对象抛「could not be converted to string」。
        return array_values(array_unique([...$defaults, ...$user], SORT_REGULAR));
    }

    /**
     * 启动自检：已安装 kode/* 包但缺少对应 ServiceProvider 接线时，明确告警。
     *
     * 薄壳框架的隐患是——包装了、但 Provider 没登记，能力就「静默失接」。这里把这种
     * 哑火变成显式 WARNING（写入日志），便于部署/排障时第一时间发现能力缺口。
     * 可用 config('app.provider_self_check') = false 关闭（如确有意的精简场景）。
     */
    private function checkProviderCoverage(): void
    {
        if (!($this->app->config->get('app.provider_self_check', true))) {
            return;
        }

        $active = $this->providers();
        $root = $this->basePath;

        foreach (self::CAPABILITY_PROVIDERS as $package => $providerClass) {
            if (!is_dir($root . '/vendor/' . $package)) {
                continue; // 包未安装，跳过
            }

            $covered = false;
            foreach ($active as $p) {
                $class = is_object($p) ? $p::class : $p;
                if (is_string($class)
                    && ($class === $providerClass
                        || (class_exists($class) && is_subclass_of($class, $providerClass)))
                ) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                logger()->warning(sprintf(
                    '启动自检：已安装 %s 但缺少对应 ServiceProvider 接线（期望 %s），该能力将不可用。'
                        . '请在 config/app.php 的 providers 中登记，或实现对应 Provider。',
                    $package,
                    $providerClass,
                ));
            }
        }
    }

    /**
     * 运行时模式：默认启用 fiber（单进程协程，NTS/ZTS 均可用）。
     * config/app.php 可覆盖为 ['fiber','process'] 或 ZTS 下 ['fiber','parallel']。
     *
     * @return array<int, string>
     */
    private function runtimeModes(): array
    {
        $modes = $this->preloadAppConfig()['runtime'] ?? ['fiber'];

        return is_array($modes) && $modes !== [] ? $modes : ['fiber'];
    }

    /**
     * 在 App::boot() 之前预读 config/app.php，用于 providers / runtime 合并。
     * 仅读取单个文件，不依赖完整的配置加载流程。
     *
     * v0.8.42（M2）：结果缓存于 {@see $preloaded}，引导期内多次调用只 require 一次，
     * 消除顶层副作用重复执行风险。
     *
     * @return array<string, mixed>
     */
    private function preloadAppConfig(): array
    {
        if ($this->preloaded !== null) {
            return $this->preloaded;
        }

        $file = $this->basePath . '/config/app.php';
        if (!is_file($file)) {
            return $this->preloaded = [];
        }

        $config = (require $file) ?: [];

        return $this->preloaded = is_array($config) ? $config : [];
    }

    private function loadEnvironment(): void
    {
        \Kode\Framework\Support\EnvLoader::load($this->basePath . '/.env');
    }

    private function loadUserBootstrap(): void
    {
        $bootstrap = $this->basePath . '/app/bootstrap.php';
        if (is_file($bootstrap)) {
            (static function (Application $app) use ($bootstrap): void {
                require $bootstrap;
            })($this);
        }
    }
}
