<?php

declare(strict_types=1);

namespace Kode\Framework\Providers;

use Kode\Framework\Providers\ServiceProvider;
use Kode\Framework\Translation\Translator;

/**
 * 国际化服务提供者（复用 symfony/translation）
 *
 * 依据 config/locale.php 构建 Translator 单例并绑定到容器，
 * 门面 Translator / 助手 lang()、translator() 复用它。
 * 加载目录优先级：应用 lang 目录 → 框架内置 resources/lang（可被应用覆盖）。
 */
final class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Translator::class, function (): Translator {
            $config = (array) $this->config('locale', []);

            return new Translator($config, $this->langPaths($config));
        });

        $this->container->alias('translator', Translator::class);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function langPaths(array $config): array
    {
        $paths = [];

        if (!empty($config['path']) && is_string($config['path'])) {
            $paths[] = $config['path'];
        }

        $paths[] = $this->basePath('lang');

        $framework = dirname(__DIR__, 2) . '/resources/lang';
        if (is_dir($framework)) {
            $paths[] = $framework;
        }

        return array_values(array_unique($paths));
    }
}
