<?php

declare(strict_types=1);

namespace Kode\Framework\Http\Support;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 受信代理判定与真实客户端 IP 解析工具（v1.0.0 新增）。
 *
 * 背景：框架多处在直接信任 `X-Forwarded-For` / `X-Real-IP` / `X-User-Id` 等客户端可伪造的
 * 请求头，导致限流可绕过（伪造 XFF 刷额度）、审计溯源失真、灰度分桶可被操纵（H4）。
 *
 * 修复思路（默认安全）：
 *  - 只有「直连对端（REMOTE_ADDR）命中受信代理列表」时，才采信代理追加的转发头；
 *  - 受信列表配置项 `trusted_proxies`：IP / CIDR / `*`（信任一切直连，仅限内网网关场景）。
 *  - 默认 `[]`（不信任任何代理）→ 一律回退 REMOTE_ADDR，杜绝伪造。
 *
 * X-Forwarded-For 解析遵循 RFC 7239 安全实践：**从右往左**取第一个「非受信」地址
 * （最右段通常由最外层受信代理追加；越靠右越接近真实客户端链路），全部受信时取最右段。
 */
final class TrustedProxies
{
    /**
     * 判断直连对端是否为受信代理。
     *
     * @param array<int, string> $trusted IP / CIDR / '*' 列表
     */
    public static function isTrusted(string $remoteAddr, array $trusted): bool
    {
        $remoteAddr = trim($remoteAddr);
        if ($remoteAddr === '') {
            return false;
        }

        foreach ($trusted as $entry) {
            if (is_string($entry) && trim($entry) === '*') {
                return true;
            }

            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '/')) {
                if (self::cidrMatch($remoteAddr, $entry)) {
                    return true;
                }

                continue;
            }

            if ($remoteAddr === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析请求的真实客户端 IP。
     *
     * @param array<int, string> $trusted 受信代理列表（IP / CIDR / '*'）；默认 [] = 只认直连
     */
    public static function clientIp(ServerRequestInterface $request, array $trusted): string
    {
        $remote = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return 'unknown';
        }
        // 快路径（限流/审计热路径命中最多）：默认 [] = 不信任任何代理，直接返回对端地址，
        // 免去 isTrusted 空列表遍历。语义与下方完整判定完全等价。
        if ($trusted === []) {
            return $remote;
        }
        if (!self::isTrusted($remote, $trusted)) {
            // 直连对端不可信：不采信任何转发头，直接用对端地址。
            return $remote;
        }

        // 直连对端是受信代理：从右往左取 X-Forwarded-For 第一个非受信地址。
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $chain = array_values(array_filter(array_map('trim', explode(',', $xff)), fn (string $ip): bool => $ip !== ''));

            for ($i = count($chain) - 1; $i >= 0; --$i) {
                if (!self::isTrusted($chain[$i], $trusted)) {
                    return $chain[$i];
                }
            }

            // 整条链均为受信代理（不再有真实客户端信息）：取最右段作为兜底。
            if ($chain !== []) {
                return $chain[array_key_last($chain)];
            }
        }

        $real = $request->getHeaderLine('X-Real-IP');
        if ($real !== '') {
            return trim(explode(',', $real)[0]) ?: $remote;
        }

        return $remote;
    }

    /**
     * IPv4 / IPv6 CIDR 匹配（inet_pton 二进制按位与）。
     */
    private static function cidrMatch(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $network = trim($network);

        $isV6 = str_contains($ip, ':') || str_contains($network, ':');
        $width = $isV6 ? 128 : 32;
        $bits = $bits === null ? $width : (int) $bits;
        if ($bits < 0 || $bits > $width) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        $mask = str_repeat("\xff", $bytes);
        if ($remainder > 0) {
            $mask .= chr(0xff << (8 - $remainder));
        }
        $mask = str_pad($mask, intdiv($width, 8), "\0");

        return ($ipBin & $mask) === ($netBin & $mask);
    }
}