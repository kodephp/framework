<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Session\SessionManager;

/**
 * 会话服务提供者（kode/session，薄壳委托）
 *
 * 此前框架「最大坑」之一：kode/session 已安装，却没有任何 ServiceProvider / 中间件接线，
 * 会话能力「静默失接」——装了包、写了配置，运行时却拿不到会话（无状态会话 / CSRF 等空白）。
 *
 * 本 Provider 把会话接进生命周期：
 *  - 绑定 SessionManager 单例（按 config/session.php 构造，支持 file/array/redis/cookie/database 驱动）；
 *  - 由 HttpServiceProvider 注册 SessionMiddleware（auto_start + 响应时落盘 + GC）；
 *  - 业务侧用 session() 助手读写当前请求会话（见 src/Support/helpers.php）。
 */
final class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SessionManager::class, function (): SessionManager {
            /** @var array<string, mixed> $config */
            $config = (array) $this->config('session', []);

            // 配置加载期 app() 尚未就绪，storage_path() 会退化成相对路径，
            // 导致 FileDriver 锁目录解析失败（500）。此处用已就绪的 base_path 解析为绝对路径并确保目录存在。
            // 路径统一落在 storage/sessions（复数，匹配 .gitignore 既有约定）。
            if (($config['drivers']['file']['path'] ?? null) === null) {
                $config['drivers']['file']['path'] = storage_path('sessions');
            }
            $this->ensureDirectory($config['drivers']['file']['path']);

            return SessionManager::create($config);
        });

        $this->container->alias('session.manager', SessionManager::class);
    }

    /**
     * 确保目录存在（递归创建），失败抛出明确异常而非由 kode/session 抛无意义的锁错误。
     */
    private function ensureDirectory(string $dir): void
    {
        if ($dir === '' || is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建会话存储目录: {$dir}");
        }
    }
}
