<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Kode\Framework\Http\Attributes\Controller as RouteController;
use Kode\Framework\Http\Attributes\Delete;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;
use Kode\Framework\Http\Controller;

/**
 * 属性路由示例控制器。
 *
 * 本类演示「自动路由匹配」：用 #[Controller] / #[Get] 等属性声明意图，
 * 框架启动时自动发现并注册，无需在 routes.php 里逐条手写。
 * 方法上的 name 仍支持命名路由（route('product.show', ['id' => 1])）。
 *
 * 可用 `php bin/kode console route:list` 查看自动登记的路由。
 */
#[RouteController(prefix: '/products')]
final class ProductsController extends Controller
{
    /**
     * GET /products
     */
    #[Get('')]
    public function index()
    {
        return $this->ok([
            ['id' => 1, 'name' => 'Kode 键盘'],
            ['id' => 2, 'name' => 'Kode 鼠标'],
        ], '商品列表');
    }

    /**
     * GET /products/{id:\d+}  （命名路由 product.show）
     */
    #[Get('/{id:\d+}', name: 'product.show')]
    public function show()
    {
        $id = (int) $this->param('id');

        return $this->ok(['id' => $id, 'name' => '商品 #' . $id], '商品详情');
    }

    /**
     * POST /products
     */
    #[Post('')]
    public function store()
    {
        $data = $this->validate($this->input(), [
            'name'  => 'required|min:2|max:50',
            'price' => 'required|numeric|min:0',
        ]);

        return $this->ok($data, '创建成功');
    }

    /**
     * DELETE /products/{id:\d+}
     */
    #[Delete('/{id:\d+}')]
    public function destroy()
    {
        $id = (int) $this->param('id');

        return $this->ok(['id' => $id], '已删除');
    }
}
