<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Aop\Aop;
use Kode\Aop\Contract\AspectKernelInterface;
use Kode\Attributes\Reader;
use Kode\Framework\Aop\AspectScanner;
use Kode\Framework\Providers\ServiceProvider;

/**
 * AOP 服务提供者（kode/aop，薄壳委托）
 *
 * 此前框架装了 kode/aop 却没有任何 Provider 接线，切面能力「静默失接」——写了 #[Aspect]
 * 切面也不会被织入。
 *
 * 本 Provider 把 AOP 内核接进生命周期：
 *  - 按 config/aop.php 的 paths 自动发现 #[Aspect] 切面（约定优于配置）；
 *  - 合并 aspects 显式登记，统一交给 Aop::bootFromConfig 启动内核并织入；
 *  - 绑定 AspectKernelInterface 单例，aop() 助手可取内核 / diagnostics（见 helpers.php）。
 */
final class AopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config('aop', []);

        // 1) 显式登记的切面（类名字符串，优先于自动发现）。
        /** @var list<class-string> $aspects */
        $aspects = [];
        foreach ((array) ($config['aspects'] ?? []) as $a) {
            if (is_string($a)) {
                $aspects[] = $a;
            }
        }

        // 2) 自动发现 #[Aspect] 切面类（约定优于配置，与任务/路由同理念）。
        try {
            /** @var array<string, string> $paths */
            $paths = (array) ($config['paths'] ?? []);
            $dirs = [];
            foreach ($paths as $source => $rel) {
                $dirs[(string) $source] = $this->basePath((string) $rel);
            }

            foreach ((new AspectScanner(new Reader()))->scan($dirs) as $class) {
                $aspects[] = $class;
            }
        } catch (\Throwable $e) {
            // 扫描失败（app/aspects 尚未初始化等）不应阻断启动。
            $this->warn('[aop] 切面扫描失败：' . $e->getMessage());
        }

        // 3) 织入缓存目录（提升二次启动性能；默认 storage/aop）。
        $cachePath = (string) ($config['cache']['path'] ?? '');
        if ($cachePath === '') {
            $cachePath = $this->basePath('storage/aop');
        }

        $bootConfig = [
            'enable' => ($config['enabled'] ?? true) !== false,
            'aspects' => $aspects,
            'cache' => ['path' => $cachePath],
        ];

        // 严格模式是 Aop 的静态开关（落到 MetadataReader），且必须在 boot 之前落定：
        // boot 阶段会读取切面属性，编译完成后再改开关对本期内核无效。
        // 用 array_key_exists 而非 !empty，配置 false 才真能表达"回退宽容模式"。
        if (array_key_exists('strict', $config)) {
            Aop::strict((bool) $config['strict']);
        }

        try {
            $kernel = Aop::bootFromConfig($bootConfig);
        } catch (\Throwable $e) {
            // 内核启动失败不应阻断启动；记录告警并保留未启动的内核（切面不生效）。
            $this->warn('[aop] 内核启动失败：' . $e->getMessage());
            $kernel = Aop::kernel();
        }

        $this->container->instance(AspectKernelInterface::class, $kernel);
        $this->container->alias('aop', AspectKernelInterface::class);
    }

    /**
     * register() 跑在 Bootstrap 的注册阶段，此时 App 单例尚未创建，
     * logger() 助手会直接抛 RuntimeException——告警绝不能反过来掐死引导，
     * 故失败时降级到 error_log。
     */
    private function warn(string $message): void
    {
        try {
            logger()->warning($message);
        } catch (\Throwable) {
            \error_log($message);
        }
    }
}
