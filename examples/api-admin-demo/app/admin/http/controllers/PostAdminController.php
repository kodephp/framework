<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\middleware\AdminMiddleware;
use app\models\Category;
use app\models\Post;
use Kode\Framework\Database\Db;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Delete;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Put;
use Kode\Framework\Http\Controller as BaseController;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Http\Request;

#[Controller(prefix: '/admin/api/posts', middleware: [AuthMiddleware::class, AdminMiddleware::class])]
final class PostAdminController extends BaseController
{
    #[Get('')]
    public function index(Request $req)
    {
        $perPage = max(1, (int) $req->input('per_page', 10));
        $status = $req->input('status');

        $builder = Db::table('posts');
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }
        $builder->orderBy('created_at', 'desc');

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

    #[Kode\Framework\Http\Attributes\Post('')]
    public function store(Request $req)
    {
        $data = $this->validate($req->all(), [
            'title'       => 'required',
            'content'     => 'required',
            'category_id' => 'required',
            'status'      => 'required',
        ]);

        $post = Post::create([
            'title'       => $data['title'],
            'slug'        => $this->slug($data['title']),
            'content'     => $data['content'],
            'excerpt'     => mb_substr(strip_tags((string) $data['content']), 0, 120),
            'status'      => $data['status'],
            'category_id' => (int) $data['category_id'],
            'author_id'   => (int) Request::attr('auth')->uid,
            'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->json($post->toArray(), 201);
    }

    #[Get('/{id}')]
    public function show(int $id)
    {
        $post = Post::find($id);
        if ($post === null) {
            return $this->error('文章不存在', 404);
        }
        return $this->json($post->toArray());
    }

    #[Put('/{id}')]
    public function update(int $id, Request $req)
    {
        $post = Post::find($id);
        if ($post === null) {
            return $this->error('文章不存在', 404);
        }

        $data = $this->validate($req->all(), [
            'title'       => 'required',
            'content'     => 'required',
            'category_id' => 'required',
            'status'      => 'required',
        ]);

        $post->fill([
            'title'        => $data['title'],
            'content'      => $data['content'],
            'status'       => $data['status'],
            'category_id'  => (int) $data['category_id'],
            'published_at' => $data['status'] === 'published'
                ? ($post->published_at ?? date('Y-m-d H:i:s'))
                : null,
        ]);
        $post->save();

        return $this->json($post->toArray());
    }

    #[Delete('/{id}')]
    public function destroy(int $id)
    {
        $post = Post::find($id);
        if ($post === null) {
            return $this->error('文章不存在', 404);
        }
        $post->delete();
        return $this->json(['deleted' => true]);
    }

    private function slug(string $title): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($title));
        return trim($slug, '-') ?: ('post-' . time());
    }

    private function categoryArray(int $id): ?array
    {
        $cat = Category::find($id);
        return $cat ? $cat->toArray() : null;
    }
}
