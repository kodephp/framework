<?php

declare(strict_types=1);

namespace Kode\Framework\Support;

/**
 * 多应用目录发现（零开关约定）。
 *
 * 约定（与 ThinkPHP 多应用 / Webman / Hyperf multi-app 同型：顶层应用目录 + 应用内扁平结构，
 * 不再套 http 层）：
 *
 *   app/<app>/routes.php     存在 ⇒ <app> 即一个子应用（目录名即应用名，小写）
 *   app/<app>/controllers/   子应用控制器（属性路由自动扫描）
 *   app/<app>/middleware/    子应用中间件（make:middleware --app 生成，按路由声明挂载）
 *   app/<app>/console/       子应用命令（ConsoleServiceProvider 自动发现）
 *   app/<app>/models/        子应用模型（make:model --app 生成，PSR-4 app\ → app/ 自动加载）
 *   app/<app>/routes/        子应用分区路由（自动 glob）
 *
 * 不满足「存在 routes.php」的普通目录（app/services、app/models、app/console 等）
 * 不会被误判为子应用。
 *
 * 规则的唯一实现在这里：HttpServiceProvider（路由/控制器扫描）与 ConsoleServiceProvider
 * （命令扫描）共用，避免两处判断漂移。
 */
final class AppDiscovery
{
    /**
     * 发现全部子应用。
     *
     * @param string $base 项目根目录（path.base）
     * @return array<string, string> 应用名 => 应用目录绝对路径
     */
    public static function discover(#[\SensitiveParameter] string $base): array
    {
        if ($base === '') {
            return [];
        }

        $apps = [];
        foreach (glob($base . '/app/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename((string) $dir) === 'routes') {
                continue; // 主应用路由目录（归属主应用，不是子应用）
            }
            if (is_file($dir . '/routes.php')) {
                $apps[basename((string) $dir)] = (string) $dir;
            }
        }

        return $apps;
    }
}
