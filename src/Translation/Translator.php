<?php

declare(strict_types=1);

namespace Kode\Framework\Translation;

use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * 国际化翻译器（框架封装，底层 Symfony Translator）
 *
 * 复用成熟的 symfony/translation（PSR 兼容、框架无关），框架只做薄封装：
 *  - 从 lang 目录按语种加载 PHP 数组资源（资源key => 文案）；
 *  - 支持应用目录与框架内置默认目录合并（应用覆盖框架）；
 *  - 占位符遵循 Symfony 约定：%name%（例如 'user %id% not found'）。
 *
 * 使用：lang('user.not_found', ['id' => 1]) 或 translator()->trans(...)。
 */
final class Translator
{
    private SymfonyTranslator $translator;

    /** @var array<int, string> */
    private array $paths;

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $paths 候选 lang 目录（应用在前，框架默认在后）
     */
    public function __construct(array $config = [], array $paths = [])
    {
        $this->paths = $paths ?: [base_path('lang')];
        $this->translator = new SymfonyTranslator((string) ($config['default'] ?? 'zh-CN'));
        $this->translator->addLoader('array', new ArrayLoader());
        $this->loadAll();
    }

    /**
     * 加载所有候选目录下当前语种的 messages 资源。
     */
    private function loadAll(): void
    {
        $locale = $this->translator->getLocale();

        foreach ($this->paths as $path) {
            $file = rtrim((string) $path, '/') . '/' . $locale . '/messages.php';
            if (is_file($file)) {
                /** @var array<string, string> $messages */
                $messages = require $file;
                $this->translator->addResource('array', $messages, $locale);
            }
        }
    }

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->loadAll();
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }

    /**
     * 翻译文案。
     *
     * 占位符约定：%name%（与 symfony/translation 一致）。
     * 为开发者友好，参数键会自动补百分号：传 ['name' => 'Kode']
     * 等价于 symfony 的 ['%name%' => 'Kode']。
     *
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            $k = is_string($key) && str_starts_with($key, '%') && str_ends_with($key, '%')
                ? $key
                : '%' . $key . '%';
            $normalized[$k] = $value;
        }

        return $this->translator->trans($id, $normalized, $domain ?? 'messages', $locale);
    }
}
