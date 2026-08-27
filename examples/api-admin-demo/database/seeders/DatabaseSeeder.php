<?php

declare(strict_types=1);

use app\models\Category;
use app\models\Post;
use app\models\User;
use Kode\Framework\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 管理员账号（密码 admin123）
        if (User::where('username', 'admin')->first() === null) {
            User::create([
                'username'     => 'admin',
                'email'        => 'admin@example.com',
                'password'     => password_hash('admin123', PASSWORD_DEFAULT),
                'display_name' => '管理员',
                'role'         => 'admin',
            ]);
        }

        // 分类
        $cats = ['技术', '产品', '随笔'];
        $catIds = [];
        foreach ($cats as $name) {
            $slug = (string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($name));
            $cat = Category::create(['name' => $name, 'slug' => $slug]);
            $catIds[] = $cat->id;
        }

        // 示例文章
        $samples = [
            ['用 Kode 快速搭建博客 API', 'published'],
            ['JWT 鉴权在常驻进程中的实践', 'published'],
            ['草稿：多应用目录结构设计', 'draft'],
        ];
        foreach ($samples as $i => [$title, $status]) {
            Post::create([
                'title'        => $title,
                'slug'         => (string) preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($title)) . '-' . ($i + 1),
                'content'      => '# ' . $title . "\n\n这是一篇由 seeder 生成的示例文章。",
                'excerpt'      => mb_substr($title, 0, 60),
                'status'       => $status,
                'category_id'  => $catIds[$i % count($catIds)],
                'author_id'    => 1,
                'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
            ]);
        }
    }
}
