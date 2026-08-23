<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Support;

/**
 * 查询串 / 明细数据脱敏工具（v0.8.42 抽出，供审计与访问日志共用）。
 *
 * 背景：审计（AuditService）与访问日志（AccessLogMiddleware）都要把请求查询串写入日志，
 * 敏感参数（password / token / key / cookie 等）必须脱敏，否则凭证会以明文落盘（H5）。
 *
 * 本类统一实现：
 *  - 默认敏感字段名集合 {@see DEFAULT_MASK_PARAMS}（与 v0.8.41 审计服务默认值一致，
 *    保证已有部署行为不变）；
 *  - 对「& 分隔的查询串」按参数名原地替换为 `key=***`（保留原串格式，不做 URL 重编码）；
 *  - 对嵌套数组（filter[password]=x 这类 query 解构 / 事件明细）递归脱敏。
 */
final class QueryMasker
{
    /**
     * 默认敏感字段名（统一小写）。命中其一的查询参数 / 明细值将被替换为 '***'。
     */
    public const DEFAULT_MASK_PARAMS = [
        'password', 'passwd', 'pwd', 'token', 'secret', 'secrets',
        'authorization', 'api_key', 'apikey', 'access_token', 'refresh_token',
        'private_key', 'cookie', 'set-cookie', 'x-api-key', 'csrf_token', 'otp', 'pin',
    ];

    /**
     * 脱敏查询串：按 & 切分参数，对敏感参数名（兼容 filter[password] 这类嵌套键）原地替换为 ***。
     * 直接在原串上操作，保留既有格式（不做 URL 重编码，避免日志里出现 %2A%2A%2A 这类噪声）。
     *
     * @param array<int, string> $mask 已统一小写的字段名集合；传入 [] 即关闭脱敏
     */
    public static function maskQuery(string $query, array $mask): string
    {
        if ($mask === [] || $query === '') {
            return $query;
        }

        $pairs = explode('&', $query);
        foreach ($pairs as &$pair) {
            $eq = strpos($pair, '=');
            $key = $eq === false ? $pair : substr($pair, 0, $eq);
            foreach ($mask as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $pair = $key . '=***';
                    break;
                }
            }
        }
        unset($pair);

        return implode('&', $pairs);
    }

    /**
     * 递归脱敏：键命中 mask 集合的值替换为 '***'（兼容嵌套数组，如 filter[password]=x）。
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $mask 已统一小写的字段名集合
     * @return array<string, mixed>
     */
    public static function maskSensitive(array $data, array $mask): array
    {
        if ($mask === []) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::maskSensitive($value, $mask);
            } else {
                $out[$key] = in_array(strtolower((string) $key), $mask, true) ? '***' : $value;
            }
        }

        return $out;
    }

    /**
     * 统一为小写的字段名集合（配置可覆盖/关闭）。
     *
     * @param array<int, string>|null $configured 配置值；null 使用安全默认集合，[] 显式关闭
     * @return array<int, string>
     */
    public static function normalizeMaskParams(?array $configured): array
    {
        $mask = $configured ?? self::DEFAULT_MASK_PARAMS;

        return array_map('strtolower', $mask);
    }
}