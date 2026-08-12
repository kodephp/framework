<?php

/*
 * 分布式 ID 生成器（Snowflake）配置
 *
 * 生成趋势递增的 64 位整数，适合做数据库主键 / 全局唯一标识。
 * 多实例部署时务必让每个实例拥有唯一的 (datacenter_id, worker_id) 组合，
 * 否则可能生成碰撞 ID。
 */

return [
    /*
     * 机器编号（0-31）。同一数据中心内不同实例需不同。
     */
    'worker_id' => (int) env('SNOWFLAKE_WORKER_ID', 0),

    /*
     * 数据中心编号（0-31）。不同机房 / 可用区用不同值区分。
     */
    'datacenter_id' => (int) env('SNOWFLAKE_DATACENTER_ID', 0),

    /*
     * 自定义纪元（毫秒时间戳）。默认 2024-01-01T00:00:00Z。
     * 可用更接近当前的纪元以延长算法可用年限（41 位约可用 69 年，距今越近越省）。
     */
    'epoch' => (int) env('SNOWFLAKE_EPOCH', 1704067200000),
];
