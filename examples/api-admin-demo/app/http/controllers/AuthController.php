<?php

declare(strict_types=1);

namespace app\http\controllers;

use app\models\User;
use Kode\Framework\Http\Attributes\Controller;
use Kode\Framework\Http\Attributes\Get;
use Kode\Framework\Http\Attributes\Post;
use Kode\Framework\Http\Controller as BaseController;
use Kode\Framework\Http\Middleware\AuthMiddleware;
use Kode\Framework\Http\Request;

#[Controller(prefix: '/api/auth')]
final class AuthController extends BaseController
{
    #[Post('/login')]
    public function login(Request $req)
    {
        $data = $this->validate($req->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        $row = User::where('username', $data['username'])->first();
        if ($row === null) {
            return $this->error('用户名或密码错误', 401);
        }

        $id = is_object($row) ? $row->id : $row['id'];
        $hash = is_object($row) ? $row->password : $row['password'];

        if (!password_verify((string) $data['password'], (string) $hash)) {
            return $this->error('用户名或密码错误', 401);
        }

        $user = User::find($id);   // 已水合模型，字段访问安全

        $token = jwt()->issue([
            'uid'      => $user->id,
            'username' => $user->username,
            'roles'    => [$user->role],
            'custom'   => ['display_name' => $user->display_name],
        ]);

        return $this->json([
            'token' => $token,
            'user'  => [
                'id'           => $user->id,
                'username'     => $user->username,
                'display_name' => $user->display_name,
                'role'         => $user->role,
            ],
        ]);
    }

    #[Get('/me', middleware: [AuthMiddleware::class])]
    public function me()
    {
        /** @var \Kode\Jwt\Token\Payload $payload */
        $payload = Request::attr('auth');

        return $this->json([
            'id'           => $payload->uid,
            'username'     => $payload->username,
            'display_name' => $payload->custom['display_name'] ?? null,
            'roles'        => $payload->roles ?? [],
        ]);
    }
}
