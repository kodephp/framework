<?php

declare(strict_types=1);

namespace app\http\controllers;

use app\models\Category;
use app\models\Post;
use Kode\Framework\Database\Db;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Controller as BaseController;
use Kode\Framework\Http\Request;

#[Controller(prefix: '/api/posts')]
final class PostController extends BaseController
{
    #[Get('')]
    public function index(Request $req)
    {
        $perPage = max(1, (int) $req->input('per_page', 10));
        $category = $req->input('category');
        $q = $req->input('q');

        $builder = Db::table('posts')->where('status', 'published');

        if ($category !== null && $category !== '') {
            $builder->where('category_id', (int) $category);
        }
        if ($q !== null && $q !== '') {
            $builder->where('title', 'like', '%' . $q . '%');
        }

        $builder->orderBy('published_at', 'desc');

        $result = $builder->paginate($perPage);

        $data = [];
        foreach ($result['items'] as $row) {
            $item = (array) $row;
            $item['category'] = isset($item['category_id'])
                ? $this->categoryArray((int) $item['category_id'])
                : null;
            $data[] = $item;
        }

        return $this->json([
            'data' => $data,
            'meta' => [
                'total'        => $result['total'],
                'per_page'     => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page'    => $result['last_page'],
            ],
        ]);
    }

    #[Get('/{id}')]
    public function show(int $id)
    {
        $post = Post::find($id);

        if ($post === null || $post->status !== 'published') {
            return $this->error('文章不存在', 404);
        }

        $data = $post->toArray();
        $data['category'] = $post->category ? $post->category->toArray() : null;
        $data['author'] = $post->author
            ? ['id' => $post->author->id, 'display_name' => $post->author->display_name]
            : null;

        return $this->json($data);
    }

    private function categoryArray(int $id): ?array
    {
        $cat = Category::find($id);
        return $cat ? $cat->toArray() : null;
    }
}
