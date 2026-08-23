<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Core\Config\Config;
use Kode\Core\Provider\ServiceProvider as CoreServiceProvider;

/**
 * 框架服务提供者基类。
 *
 * 继承 kode/core 的 ServiceProvider（其底层即 kode/di 的 ServiceProvider，
 * 复用 bind / singleton / instance / alias 等能力）。
 *
 * 关键约束：kode/core 的 Bootstrap 在「注册服务」阶段（register/boot）
 * 时 App 单例尚未创建，因此**不可在 provider 内调用 config() 全局函数**
 * （它依赖 App 实例）。请改用本类提供的 $this->config()，
 * 它直接从容器已绑定的 Kode\Core\Config\Config 读取，启动期同样可用。
 */
abstract class ServiceProvider extends CoreServiceProvider
{
    /**
     * 从容器绑定的配置仓库读取配置（启动期安全）。
     *
     * @return mixed
     */
    protected function config(string $key, mixed $default = null)
    {
        if ($this->container->has(Config::class)) {
            return $this->container->make(Config::class)->get($key, $default);
        }

        return $default;
    }

    /**
     * 应用基路径拼接（等价于 app()->basePath()）。
     */
    protected function basePath(string $path = ''): string
    {
        $base = (string) $this->config('path.base', getcwd());
        $base = rtrim($base, '/\\');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }

    /**
     * 受信代理列表（config/security.php trusted_proxies，H4）。
     *
     * 供各需要区分「真实客户端 IP / 可信转发头」的中间件装配时注入；
     * 默认 [] = 不信任任何代理，一律以 REMOTE_ADDR 为对端地址。
     *
     * @return array<int, string>
     */
    protected function trustedProxies(): array
    {
        $trusted = $this->config('security.trusted_proxies', []);

        return is_array($trusted) ? array_values(array_map('strval', $trusted)) : [];
    }
}
