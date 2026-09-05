<?php

declare(strict_types=1);

namespace Kode\Framework\Server;

/**
 * 运行状态快照与渲染（对标 `workerman status`，由 `bin/kode status` 消费）。
 *
 * 输出两段：
 *  1. **GLOBAL STATUS**：版本、启动时间、运行时长、master pid、事件循环驱动、
 *     worker 数 / 进程数，以及按 worker 名聚合的状态。
 *  2. **PROCESS STATUS**：逐进程一行（pid / 内存 / 监听地址 / 进程名 / 连接数 /
 *     累计请求 / QPS / 状态）。
 *
 * 与 workerman 的**已知差异（不做伪装）**：
 *  - 不含 `exit_status` / `exit_count` 两列。workerman 在 master 里收割子进程并记录退出码，
 *    本框架的 master 循环位于 kode/process 内部，业务层观测不到子进程退出码；
 *    与其填 0 假装「零退出」误导排障，不如如实不列。
 *  - `connections` / `total_request` / `qps` 来自 worker 自身心跳（1Hz），
 *    非实时精确值，误差在一个心跳周期内。
 *
 * 数据来源见 {@see ServerStatusStore}。
 */
final class ServerStatus
{
    /** worker 心跳的最大陈旧容忍（秒）：超过此值视为心跳丢失，状态标为 unknown。 */
    public const STALE_AFTER = 5.0;

    public function __construct(private readonly ServerStatusStore $store)
    {
    }

    /**
     * 组装快照。
     *
     * @return array{running: bool, master: array<string, mixed>|null, workers: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $master  = $this->store->master();
        $workers = $this->store->workers();

        // master.pid 由 worker 以 ppid 写入；master 已退出时整份 master 记录即失效。
        if ($master !== null) {
            $masterPid = (int) ($master['pid'] ?? 0);
            if ($masterPid <= 1 || !ServerStatusStore::isAlive($masterPid)) {
                $master = null;
            }
        }

        $list = [];
        foreach ($workers as $pid => $row) {
            $row['pid'] = $pid;
            $list[]     = $row;
        }

        return [
            'running' => $master !== null || $list !== [],
            'master'  => $master,
            'workers' => $list,
        ];
    }

    /**
     * 渲染完整状态（workerman 风格两段式）。
     *
     * @param int|null $onlyPid 指定时只渲染该进程的详情块
     */
    public function render(?int $onlyPid = null): string
    {
        $snap = $this->snapshot();

        if (!$snap['running']) {
            return "服务未运行（未找到有效状态文件，或 master 进程已退出）\n"
                . "状态目录：" . $this->store->dir() . "\n";
        }

        if ($onlyPid !== null) {
            return $this->renderSingle($snap, $onlyPid);
        }

        return $this->renderGlobal($snap) . $this->renderProcesses($snap['workers']);
    }

    /**
     * 服务是否在跑（供 CLI 决定退出码）。
     */
    public function isRunning(): bool
    {
        return $this->snapshot()['running'];
    }

    // ------------------------------------------------------------------ 渲染

    /**
     * @param array{running: bool, master: array<string, mixed>|null, workers: list<array<string, mixed>>} $snap
     */
    private function renderGlobal(array $snap): string
    {
        $master  = $snap['master'];
        $workers = $snap['workers'];

        $out = "Kode[kode] status \n";
        $out .= "----------------------------------------------GLOBAL STATUS----------------------------------------------\n";

        $version    = (string) ($master['version'] ?? 'unknown');
        $phpVersion = (string) ($master['php_version'] ?? PHP_VERSION);
        $out .= sprintf("Kode Framework version:%-12s PHP version:%s\n", $version, $phpVersion);

        $startedAt = (float) ($master['started_at'] ?? 0.0);
        $out .= sprintf(
            "start time:%-22s run %s\n",
            $startedAt > 0 ? date('Y-m-d H:i:s', (int) $startedAt) : 'unknown',
            self::formatDuration($startedAt > 0 ? microtime(true) - $startedAt : 0.0)
        );

        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
        $out .= sprintf(
            "master pid:%-10d runtime:%-10s event-loop:%-8s load average:%s\n",
            (int) ($master['pid'] ?? 0),
            (string) ($master['runtime'] ?? 'unknown'),
            (string) ($master['loop'] ?? 'unknown'),
            $load === [] ? 'n/a' : implode(', ', array_map(static fn (float $v): string => number_format($v, 2), $load))
        );

        // 按 worker 名聚合（一个 worker 名对应 N 个进程）。
        $byName = [];
        foreach ($workers as $row) {
            $name = (string) ($row['name'] ?? 'kode-http');
            $byName[$name] = ($byName[$name] ?? 0) + 1;
        }

        $out .= sprintf("%d workers       %d processes\n", count($byName), count($workers));
        $out .= sprintf("%-16s %-10s %s\n", 'worker_name', 'processes', 'status');

        foreach ($byName as $name => $count) {
            $out .= sprintf("%-16s %-10d %s\n", $name, $count, '[OK]');
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $workers
     */
    private function renderProcesses(array $workers): string
    {
        $line = "----------------------------------------------PROCESS STATUS---------------------------------------------\n";

        $out  = $line;
        $out .= sprintf(
            "%-8s %-9s %-30s %-14s %-12s %-14s %-6s %s\n",
            'pid',
            'memory',
            'listening',
            'worker_name',
            'connections',
            'total_request',
            'qps',
            'status'
        );
        $out .= $line;

        foreach ($workers as $row) {
            $out .= sprintf(
                "%-8d %-9s %-30s %-14s %-12d %-14d %-6d %s\n",
                (int) ($row['pid'] ?? 0),
                self::formatMemory((int) ($row['memory'] ?? 0)),
                self::clip((string) ($row['listen'] ?? 'unknown'), 30),
                self::clip((string) ($row['worker_name'] ?? '-'), 14),
                (int) ($row['connections'] ?? 0),
                (int) ($row['requests'] ?? 0),
                (int) ($row['qps'] ?? 0),
                $this->workerStatus($row)
            );
        }

        return $out . $line;
    }

    /**
     * 单进程详情块（`bin/kode status --pid=N`）。
     *
     * @param array{running: bool, master: array<string, mixed>|null, workers: list<array<string, mixed>>} $snap
     */
    private function renderSingle(array $snap, int $pid): string
    {
        $master = $snap['master'];

        if ($master !== null && (int) ($master['pid'] ?? 0) === $pid) {
            return $this->renderMasterDetail($master, count($snap['workers']));
        }

        foreach ($snap['workers'] as $row) {
            if ((int) ($row['pid'] ?? 0) !== $pid) {
                continue;
            }

            $started = (float) ($row['started_at'] ?? 0.0);
            $lines   = [
                "----------------------------------------------PROCESS DETAIL---------------------------------------------",
                sprintf("%-16s %d", 'pid', $pid),
                sprintf("%-16s %s", 'worker_name', (string) ($row['worker_name'] ?? '-')),
                sprintf("%-16s %d", 'worker_id', (int) ($row['worker_id'] ?? -1)),
                sprintf("%-16s %s", 'listening', (string) ($row['listen'] ?? 'unknown')),
                sprintf("%-16s %s", 'runtime', (string) ($row['runtime'] ?? ($master['runtime'] ?? 'unknown'))),
                sprintf("%-16s %s", 'event-loop', (string) ($master['loop'] ?? 'unknown')),
                sprintf("%-16s %s", 'memory', self::formatMemory((int) ($row['memory'] ?? 0))),
                sprintf("%-16s %s", 'peak_memory', self::formatMemory((int) ($row['peak_memory'] ?? 0))),
                sprintf("%-16s %d", 'connections', (int) ($row['connections'] ?? 0)),
                sprintf("%-16s %d", 'total_request', (int) ($row['requests'] ?? 0)),
                sprintf("%-16s %d", 'qps', (int) ($row['qps'] ?? 0)),
                sprintf("%-16s %d", 'in_flight', (int) ($row['inflight'] ?? 0)),
                sprintf("%-16s %s", 'started_at', $started > 0 ? date('Y-m-d H:i:s', (int) $started) : 'unknown'),
                sprintf("%-16s %s", 'uptime', self::formatDuration($started > 0 ? microtime(true) - $started : 0.0)),
                sprintf("%-16s %s", 'status', $this->workerStatus($row)),
                "----------------------------------------------------------------------------------------------------",
            ];

            return implode("\n", $lines) . "\n";
        }

        return sprintf("未找到进程 %d（用 `kode status` 查看全部进程）\n", $pid);
    }

    /**
     * @param array<string, mixed> $master
     */
    private function renderMasterDetail(array $master, int $workerCount): string
    {
        $started = (float) ($master['started_at'] ?? 0.0);

        $lines = [
            "----------------------------------------------PROCESS DETAIL---------------------------------------------",
            sprintf("%-16s %d", 'pid', (int) ($master['pid'] ?? 0)),
            sprintf("%-16s %s", 'role', 'master'),
            sprintf("%-16s %s", 'listening', (string) ($master['listen'] ?? 'unknown')),
            sprintf("%-16s %s", 'runtime', (string) ($master['runtime'] ?? 'unknown')),
            sprintf("%-16s %s", 'event-loop', (string) ($master['loop'] ?? 'unknown')),
            sprintf("%-16s %d", 'configured_workers', (int) ($master['workers'] ?? 0)),
            sprintf("%-16s %d", 'live_processes', $workerCount),
            sprintf("%-16s %d", 'graceful_timeout', (int) ($master['graceful_timeout'] ?? 0)),
            sprintf("%-16s %s", 'daemon', !empty($master['daemon']) ? 'yes' : 'no'),
            sprintf("%-16s %s", 'project_root', (string) ($master['root'] ?? 'unknown')),
            sprintf("%-16s %s", 'started_at', $started > 0 ? date('Y-m-d H:i:s', (int) $started) : 'unknown'),
            sprintf("%-16s %s", 'uptime', self::formatDuration($started > 0 ? microtime(true) - $started : 0.0)),
            sprintf("%-16s %s", 'status', '[OK]'),
            "----------------------------------------------------------------------------------------------------",
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * 单进程状态标签：busy（有在途请求）> idle（心跳新鲜）> unknown（心跳陈旧）。
     *
     * @param array<string, mixed> $row
     */
    private function workerStatus(array $row): string
    {
        $age = microtime(true) - (float) ($row['updated_at'] ?? 0.0);
        if ($age > self::STALE_AFTER) {
            return '[unknown]';
        }

        return ((int) ($row['inflight'] ?? 0)) > 0 ? '[busy]' : '[idle]';
    }

    // ------------------------------------------------------------------ 工具

    /** 字节 → 人类可读（workerman 风格，1024 进制，保留两位）。 */
    public static function formatMemory(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0M';
        }

        return number_format($bytes / 1048576, 2, '.', '') . 'M';
    }

    /** 秒 → `X days Y hours Z minutes`（workerman 风格）。 */
    public static function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf(
            '%d days %d hours %d minutes',
            intdiv($seconds, 86400),
            intdiv($seconds % 86400, 3600),
            intdiv($seconds % 3600, 60)
        );
    }

    /** 超长字段截断（避免撑坏表格列宽）。 */
    private static function clip(string $value, int $width): string
    {
        if (strlen($value) <= $width) {
            return $value;
        }

        return substr($value, 0, max(1, $width - 1)) . '…';
    }
}
