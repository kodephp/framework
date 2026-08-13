<?php

declare(strict_types=1);

namespace Kode\Framework\Config;

use RuntimeException;

/**
 * 文件配置源（内置后端）。
 *
 * 把一份本地 PHP / JSON 文件当作配置覆盖层。既是立即可用的本地后端，也是远程配置中心
 * 的「本地镜像」范本：应用侧 watch 远程中心 → 写入此文件 → 调 config:center:reload 生效。
 *
 * options：
 *   - path：文件绝对路径（config 加载期 app() 未就绪，请用 __DIR__ 或 env 给绝对路径）。
 *   - name：源名称（默认取文件名）。
 */
final class FileConfigSource implements ConfigSource
{
    public function __construct(private readonly array $options = [])
    {
    }

    public function name(): string
    {
        return (string) ($this->options['name'] ?? basename((string) ($this->options['path'] ?? 'file')));
    }

    public function isReloadable(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = (string) ($this->options['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return [];
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $data = require $path;

            return is_array($data) ? $data : [];
        }

        if ($ext === 'json') {
            $raw = (string) file_get_contents($path);
            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(sprintf('ConfigCenter: JSON 解析失败 %s: %s', $path, json_last_error_msg()));
            }

            return is_array($data) ? $data : [];
        }

        throw new RuntimeException(sprintf('ConfigCenter: 不支持的文件类型 %s（仅 php/json）', $path));
    }
}
