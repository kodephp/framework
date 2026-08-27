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
            // RCE 防护（H5）：CONFIG_CENTER_FILE 若指向攻击者可写路径，require 即代码执行。
            // 仅允许项目根（getcwd / path.base）或系统临时目录内的文件；默认 enabled=false 已降低风险，
            // 单测（sys_get_temp_dir）需放行以便隔离。
            $real = realpath($path);
            $base = realpath((string) (getcwd() ?: __DIR__ . '/../../'));
            $tmp = realpath(sys_get_temp_dir());
            $allowed = false;
            if ($real !== false) {
                if ($base !== false && str_starts_with($real, $base)) {
                    $allowed = true;
                } elseif ($tmp !== false && str_starts_with($real, $tmp)) {
                    $allowed = true;
                }
            }
            if (!$allowed) {
                throw new RuntimeException(sprintf('ConfigCenter: php 配置源仅允许项目根或临时目录内的文件 %s', $path));
            }
            $data = require $real;
            if (!is_array($data)) {
                throw new RuntimeException(sprintf('ConfigCenter: php 配置源必须返回 array %s', $path));
            }

            return $data;
        }

        if ($ext === 'json') {
            $raw = file_get_contents($path);
            if ($raw === false) {
                // 区分「读不到」与「解析失败」：旧实现把 false 强转空串后误报 JSON 错误，
                // 误导排障方向（实际是权限 / 文件被删）。
                throw new RuntimeException(sprintf('ConfigCenter: 配置文件不可读 %s', $path));
            }
            $data = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(sprintf('ConfigCenter: JSON 解析失败 %s: %s', $path, json_last_error_msg()));
            }

            return is_array($data) ? $data : [];
        }

        throw new RuntimeException(sprintf('ConfigCenter: 不支持的文件类型 %s（仅 php/json）', $path));
    }
}
