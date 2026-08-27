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

            // 配置加载期全局 app() 尚未就绪，storage_path() 会退化成 CWD 相对路径
            // （从非项目根目录启动时会话文件会落错位置）。此处经 ServiceProvider::basePath()
            // （config('path.base')，启动期已预置）解析为绝对路径并确保目录存在。
            // 路径统一落在 storage/sessions（复数，匹配 .gitignore 既有约定）。
            if (($config['drivers']['file']['path'] ?? null) === null) {
                $config['drivers']['file']['path'] = $this->basePath('storage/sessions');
            }
            $this->ensureDirectory($config['drivers']['file']['path']);

            // 安全加固（H1/H7）：production 下 Cookie 未设 Secure 或 SameSite=None 却无 Secure 时告警/抛错。
            $env = (string) ($this->config('app.env', 'local'));
            $isProd = $env === 'production';
            $secure = (bool) ($config['secure'] ?? false);
            $sameSite = strtolower((string) ($config['samesite'] ?? 'lax'));
            if ($sameSite === 'none' && !$secure) {
                throw new \RuntimeException('会话配置错误：SameSite=None 必须配合 secure=true，否则浏览器将拒绝下发 Cookie。');
            }
            if ($isProd && !$secure) {
                error_log('[security] WARNING: SESSION_SECURE=false 但 APP_ENV=production，会话 Cookie 将经 HTTP 明文传输，建议置 SESSION_SECURE=true。');
            }

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
