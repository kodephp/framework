<?php

declare(strict_types=1);

namespace Kode\Framework\Console\Commands\Concerns;

/**
 * 脚手架命令公共能力：路径解析、目录创建、文件写入、命名转换。
 *
 * 命令以「项目根目录」为基准写文件；项目根默认取当前工作目录（bin/kode 始终
 * 在项目根执行），测试可注入 $basePath 覆盖。
 */
trait GeneratesFiles
{
    /**
     * 项目根目录（测试可注入，否则取 CWD）。
     */
    protected string $basePath = '';

    /**
     * 解析项目根：注入优先，否则 CWD。
     */
    protected function root(): string
    {
        return $this->basePath !== '' ? rtrim($this->basePath, '/') : getcwd();
    }

    /**
     * 在项目根下拼绝对路径。
     */
    protected function path(string $relative): string
    {
        return $this->root() . '/' . ltrim($relative, '/');
    }

    /**
     * 递归建目录（已存在则跳过）。
     */
    protected function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建目录：{$dir}");
        }
    }

    /**
     * 写入文件。已存在且未加 --force 时跳过并报错。
     *
     * @return bool 是否实际写入
     */
    protected function writeFile(string $path, string $content, bool $force): bool
    {
        if (is_file($path) && !$force) {
            return false;
        }

        $this->ensureDir(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("无法写入文件：{$path}");
        }

        return true;
    }

    /**
     * StudlyCase（首字母大写的驼峰）：create_users_table → CreateUsersTable，
     * SendNewsletter / UserController → 保持原有驼峰（不整体小写化）。
     */
    protected function studly(string $value): string
    {
        // 先在大写字母前插入空格，拆分驼峰（SendNewsletter → "Send Newsletter"）。
        $value = (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', (string) $value);
        // 非字母数字统一为空格。
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $value);
        $value = trim($value);

        return str_replace(' ', '', ucwords($value));
    }

    /**
     * snake_case（小写蛇形）：CreateUsersTable → create_users_table。
     */
    protected function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<=\p{L})(?=\p{Lu})/u', '_', $value);
        $value = (string) preg_replace('/[^\pL\pN]+/u', '_', $value);

        return strtolower(trim($value, '_'));
    }

    /**
     * 复数蛇形（用于表名推测）：User → users，Category → categories。
     */
    protected function snakePlural(string $value): string
    {
        $snake = $this->snake($value);
        if (preg_match('/[^aeiou]y$/i', $snake)) {
            return substr($snake, 0, -1) . 'ies';
        }
        if (preg_match('/(s|ss|sh|ch|x|z)$/i', $snake)) {
            return $snake . 'es';
        }

        return $snake . 's';
    }
}
