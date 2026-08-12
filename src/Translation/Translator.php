<?php

declare(strict_types=1);

namespace Kode\Framework\Translation;

use Kode\Context\Context;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator as SymfonyTranslator;

/**
 * 国际化翻译器（框架封装，底层 Symfony Translator）
 *
 * 复用成熟的 symfony/translation（PSR 兼容、框架无关），框架只做薄封装：
 *  - 从 lang 目录按语种加载 PHP 数组资源（资源key => 文案）；
 *  - 按「域/模块」(domain) 组织：lang/<locale>/messages.php 为默认域，
 *    lang/<locale>/<module>.php 为某业务模块的专属文案（多模块互不污染）；
 *  - 应用目录与框架内置默认目录合并（应用覆盖框架）；
 *  - 占位符遵循 Symfony 约定：%name%（例如 'user %id% not found'）。
 *
 * 多模块用法：
 *  - lang('module::key')            // 自动按 module 域取文案
 *  - translator()->trans('key', [], 'module')   // 显式指定域
 *  - translator()->transModule('module', 'key') // 语义化写法
 *
 * 使用：lang('user.not_found', ['id' => 1]) 或 translator()->trans(...)。
 */
final class Translator
{
    private SymfonyTranslator $translator;

    /** @var array<int, string> */
    private array $paths;

    /** 已加载资源的「语种:域」缓存，避免重复扫描磁盘。 */
    private array $loaded = [];

    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $paths 候选 lang 目录（应用在前，框架默认在后）
     */
    public function __construct(array $config = [], array $paths = [])
    {
        $this->paths = $paths ?: [base_path('lang')];
        $default = (string) ($config['default'] ?? 'zh-CN');
        $this->translator = new SymfonyTranslator($default);
        $this->translator->addLoader('array', new ArrayLoader());
        $this->loadDomain($default, 'messages');
    }

    /**
     * 加载指定语种、指定域在所有候选目录下的 messages 资源（幂等）。
     */
    private function loadDomain(string $locale, string $domain): void
    {
        $key = $locale . ':' . $domain;
        if (isset($this->loaded[$key])) {
            return;
        }

        foreach ($this->paths as $path) {
            $file = rtrim((string) $path, '/') . '/' . $locale . '/' . $domain . '.php';
            if (is_file($file)) {
                /** @var array<string, string> $messages */
                $messages = require $file;
                if (is_array($messages)) {
                    $this->translator->addResource('array', $messages, $locale, $domain);
                }
            }
        }

        $this->loaded[$key] = true;
    }

    /**
     * 程序化切换默认语种（同步改写实例默认 + 加载资源）。
     * 注意：并发运行时（fiber/Swoole）请改用 kode/context 的 'locale' 键，
     * 避免污染其它请求；LocaleMiddleware 已自动走 context 路径。
     */
    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->loadDomain($locale, 'messages');
    }

    /**
     * 当前生效语种：优先取请求上下文（kode/context，按 fiber/协程隔离），
     * 否则取实例默认语种。
     */
    public function getLocale(): string
    {
        if (class_exists(Context::class) && Context::has('locale')) {
            return (string) Context::get('locale');
        }

        return $this->translator->getLocale();
    }

    /**
     * 翻译文案。
     *
     * 多模块（域）支持：
     *  - $id 形如 'module::key' 会自动拆出域 module（当 $domain 为 null 时）；
     *  - 显式传入 $domain 则以其为准；缺省为 'messages'。
     *
     * 占位符约定：%name%（与 symfony/translation 一致）。
     * 为开发者友好，参数键会自动补百分号：传 ['name' => 'Kode']
     * 等价于 symfony 的 ['%name%' => 'Kode']。
     *
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ($domain === null && str_contains($id, '::')) {
            [$domain, $id] = explode('::', $id, 2);
        }

        $domain ??= 'messages';
        $locale ??= $this->getLocale();

        $this->loadDomain($locale, $domain);

        $normalized = [];
        foreach ($parameters as $key => $value) {
            $k = is_string($key) && str_starts_with($key, '%') && str_ends_with($key, '%')
                ? $key
                : '%' . $key . '%';
            $normalized[$k] = $value;
        }

        return $this->translator->trans($id, $normalized, $domain, $locale);
    }

    /**
     * 按模块（域）翻译：transModule('order', 'created', [...]) 等价于
     * trans('order::created', ...) 或 trans('created', [], 'order')。
     *
     * @param array<string, mixed> $parameters
     */
    public function transModule(string $module, string $id, array $parameters = [], ?string $locale = null): string
    {
        return $this->trans($id, $parameters, $module, $locale);
    }

    /**
     * 某语种下某域是否已加载过文案资源。
     */
    public function hasDomain(string $locale, string $domain = 'messages'): bool
    {
        return isset($this->loaded[$locale . ':' . $domain]);
    }
}
