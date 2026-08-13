<?php

declare(strict_types=1);

namespace Kode\Framework\Config;

use Closure;
use Kode\Core\Config\Config;

/**
 * 配置中心管理器（薄壳层运行时核心）。
 *
 * 职责：
 *   1) 聚合一组 ConfigSource（可插拔后端）；
 *   2) seed()：启动期把各源合并进 Config（中心值覆盖 config/*.php 文件值）；
 *   3) reload()：运行期重新拉取「可重载」源并合并，派发 ConfigReloaded 事件；
 *   4) 暴露 sources / 最近重载时间 / 变化键，供诊断与测试。
 *
 * 注意：
 *   - 多进程（kode/process master-worker）下每个 worker 各自持有一份 Config；
 *     reload() 由调用方决定作用范围（CLI 命令只影响当前进程；远程中心 watch 需在每 worker 触发，
 *     或由进程信号统一通知——后者交给应用/运维编排，框架不越界）。
 *   - 事件分发用构造注入的可选 callable 解耦，避免与事件系统启动顺序耦合。
 */
final class ConfigCenter
{
    /** @var array<string, ConfigSource> */
    private array $sources = [];

    private ?int $lastReloadAt = null;

    /** @var array<int, string> */
    private array $lastChangedKeys = [];

    /**
     * @param iterable<ConfigSource>    $sources    配置源
     * @param callable|null             $dispatcher 事件派发器（注入，避免与事件系统启动顺序耦合）
     */
    public function __construct(
        private readonly Config $config,
        iterable $sources = [],
        private readonly ?Closure $dispatcher = null,
    ) {
        foreach ($sources as $source) {
            $this->add($source);
        }
    }

    public function add(ConfigSource $source): void
    {
        $this->sources[$source->name()] = $source;
    }

    /**
     * 启动期播种：把各源合并进 Config（幂等，可重复调用）。
     */
    public function seed(): void
    {
        foreach ($this->sources as $source) {
            $this->config->merge($source->load());
        }
    }

    /**
     * 运行期热重载：仅重新拉取「可重载」源并合并，返回变化的顶层键。
     *
     * @return array<int, string> 本次发生变化的顶层键
     */
    public function reload(): array
    {
        $before = $this->config->all();

        foreach ($this->sources as $source) {
            if ($source->isReloadable()) {
                $this->config->merge($source->load());
            }
        }

        $after = $this->config->all();
        $changed = $this->changedTopKeys($before, $after);

        $this->lastReloadAt = time();
        $this->lastChangedKeys = $changed;

        if ($this->dispatcher !== null) {
            ($this->dispatcher)(new ConfigReloaded($changed, $this->lastReloadAt));
        }

        return $changed;
    }

    /**
     * @return array<int, string>
     */
    public function sources(): array
    {
        return array_keys($this->sources);
    }

    public function lastReloadAt(): ?int
    {
        return $this->lastReloadAt;
    }

    /**
     * @return array<int, string>
     */
    public function lastChangedKeys(): array
    {
        return $this->lastChangedKeys;
    }

    /**
     * 对比重载前后配置树，返回发生变化的「顶层键」（足够驱动运行期再配置决策）。
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, string>
     */
    private function changedTopKeys(array $before, array $after): array
    {
        $keys = array_keys($before + $after);
        $changed = [];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $before) || !array_key_exists($k, $after) || $before[$k] !== $after[$k]) {
                $changed[] = (string) $k;
            }
        }

        return $changed;
    }
}
