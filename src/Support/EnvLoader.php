<?php

declare(strict_types=1);

namespace Kode\Framework\Support;

/**
 * .env 解析与加载（框架引导期使用）
 *
 * 负责把 .env 里的键值对写入 $_ENV / $_SERVER / putenv，使框架的 env() 助手
 * 与 kode 生态的 getenv() 取值保持一致。
 *
 * 相比「裸 require 一个返回数组的 env.php」，这里用一个健壮的解析器，能容忍：
 *  - `export KEY=val` 前缀（兼容 shell 导出写法）；
 *  - 行内注释 `KEY=val # comment`（仅当 # 前有空白，避免误伤 URI 中的 #）；
 *  - UTF-8 BOM（文件首行）；
 *  - 空 key / 缺 = 的行（跳过）；
 *  - 单/双引号包裹的值（自动去引号）。
 *
 * 解析逻辑抽成静态方法，便于独立单测，不依赖文件系统。
 */
final class EnvLoader
{
    /**
     * 解析单个 .env 行，返回 [key, value] 或 null（注释 / 空行 / 非法行）。
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseLine(string $line): ?array
    {
        // 去除行内注释：仅当 # 前面是空白（空格/Tab）时才视为注释起点，
        // 以保留 URI 等值中的 #（如 redis://127.0.0.1:6379#db）。
        // 引号感知（v0.8.52 修复）：引号内的 # 不视为注释——旧正则先剥注释再剥引号，
        // `KEY="abc # def"` 会被截成 `KEY="abc`，最终值带脏引号。
        $line = self::stripComment($line);

        $line = trim($line);

        // 注释行 / 空行 / 无赋值符。
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            return null;
        }

        // 兼容 `export KEY=val` 写法。
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, strlen('export '));
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        // 空 key（如 `=val` 或 `export =val`）直接跳过。
        if ($key === '') {
            return null;
        }

        $value = trim($value);
        $value = self::stripQuotes($value);

        return [$key, $value];
    }

    /**
     * 读取 .env 文件并写入环境变量（幂等：重复调用不会清掉既有的合法值）。
     *
     * 仅当对应名在 $_SERVER / $_ENV 中尚不存在时才写入，避免覆盖已通过
     * 真实环境变量注入的值（12-factor 优先真实环境）。
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        // 去首行 UTF-8 BOM（\xEF\xBB\xBF）。
        if (isset($raw[0]) && str_starts_with($raw[0], "\xEF\xBB\xBF")) {
            $raw[0] = substr($raw[0], 3);
        }

        foreach ($raw as $line) {
            $pair = self::parseLine($line);
            if ($pair === null) {
                continue;
            }

            [$key, $value] = $pair;
            if (isset($_SERVER[$key]) || isset($_ENV[$key])) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * 引号感知地剥离行内注释：跟踪单/双引号开合状态，仅剥离引号外、
     * 且 # 前为空白（或位于行首）的注释段。不支持转义引号（.env 惯例中罕见）。
     */
    private static function stripComment(string $line): string
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($line);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $line[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;

                continue;
            }
            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;

                continue;
            }
            if ($ch === '#' && !$inSingle && !$inDouble
                && ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t")) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    private static function stripQuotes(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
