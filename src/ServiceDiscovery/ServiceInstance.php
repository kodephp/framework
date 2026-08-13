<?php

declare(strict_types=1);

namespace Kode\Framework\ServiceDiscovery;

/**
 * 服务实例（运行期表示）。
 *
 * 身份字段（id/name/host/port/scheme/metadata/weight）不可变；
 * 健康相关字段（healthy/lastHealthAt）由 ServiceRegistry 的 heartbeat() 在运行期更新，
 * 反映实例当前可用性。这是一个「运行期值对象」，而非纯 DDD 不可变值对象——健康状态天然是易变的。
 */
final class ServiceInstance
{
    /**
     * @param string              $id           实例唯一标识（默认 scheme://host:port 或显式 id）
     * @param string              $name         所属服务名（如 "payment"）
     * @param string              $host         主机（IP 或域名）
     * @param int                 $port         端口
     * @param string              $scheme      协议（http/https/grpc...，默认 http）
     * @param array<string, mixed> $metadata   附加元数据（区域/版本/标签等）
     * @param int                 $weight      负载均衡权重（默认 1）
     * @param bool                $healthy     当前是否健康（心跳更新）
     * @param int|null            $lastHealthAt 最近一次心跳时间戳
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $scheme = 'http',
        public readonly array $metadata = [],
        public readonly int $weight = 1,
        public bool $healthy = true,
        public ?int $lastHealthAt = null,
    ) {
    }

    /**
     * 完整访问地址（scheme://host:port）。
     */
    public function url(): string
    {
        return sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port);
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }
}
