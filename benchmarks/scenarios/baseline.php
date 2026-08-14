<?php

declare(strict_types=1);

namespace Kode\Bench\Scenario;

/**
 * 裸 PHP 基线（无框架）。
 *
 * 仅执行与 kode /bench/json 等价的「纯业务逻辑」——构造 50 条记录数组 + JSON 序列化。
 * 不含任何路由、中间件、DI 容器或 PSR-7 对象开销。其耗时作为「应用逻辑下限」，
 * 与框架场景之差即为框架 + 中间件栈的增量开销。
 */
final class Baseline
{
    public static function scenario(): callable
    {
        return static function (): ?int {
            $data = [
                'framework' => 'baseline',
                'now'       => date('c'),
                'items'     => array_map(
                    static fn (int $i) => ['id' => $i, 'name' => "item-$i"],
                    range(1, 50)
                ),
            ];

            json_encode($data);

            return null;
        };
    }
}
