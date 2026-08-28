<?php

declare(strict_types=1);

namespace app\admin\http\controllers;

use app\http\middleware\AdminMiddleware;
use app\models\Post;
use app\models\User;
use Kode\Framework\Database\Db;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Controller as BaseController;
use Kode\Framework\Http\Middleware\AuthMiddleware;

#[Controller(prefix: '/admin/api/dashboard', middleware: [AuthMiddleware::class, AdminMiddleware::class])]
final class DashboardController extends BaseController
{
    #[Get('')]
    public function index()
    {
        return $this->json([
            'posts_total'     => (int) Db::table('posts')->count(),
            'posts_published' => (int) Db::table('posts')->where('status', 'published')->count(),
            'posts_draft'     => (int) Db::table('posts')->where('status', 'draft')->count(),
            'users_total'     => (int) Db::table('users')->count(),
            'recent_posts'    => Db::table('posts')->orderBy('created_at', 'desc')->limit(5)->get(),
        ]);
    }
}
