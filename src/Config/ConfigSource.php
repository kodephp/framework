<?php

declare(strict_types=1);

namespace Kode\Framework\Config;

/**
 * 配置源契约（配置中心薄壳层的核心抽象）。
 *
 * 一个 ConfigSource 就是把「某处的一坨配置」暴露成一份配置树 fragment（数组）。
 * 框架负责把它合并进 Kode\Core\Config\Config，并支持运行期热重载。
 *
 * 内置实现：
 *   - FileConfigSource：本地 PHP/JSON 文件（可作远程中心的本地镜像 / 覆盖层）。
 *
 * 应用侧扩展（远程中心）：实现本接口并加入 config/center.php 的 sources 即可，例如：
 *   - NacosConfigSource / ApolloConfigSource / EtcdConfigSource
 *   - 在构造里接收中心地址 / dataId / 命名空间；load() 拉取并解析成数组返回。
 * 框架无需为任何具体中心改动一行代码。
 */
interface ConfigSource
{
    /**
     * 源名称（用于诊断、事件、去重）。
     */
    public function name(): string;

    /**
     * 拉取配置树 fragment（点号路径由 Config::merge 处理）。
     *
     * @return array<string, mixed>
     */
    public function load(): array;

    /**
     * 是否支持运行期热重载。
     *
     * 远程中心通常返回 true；纯静态源可返回 false（reload 时跳过）。
     */
    public function isReloadable(): bool;
}
