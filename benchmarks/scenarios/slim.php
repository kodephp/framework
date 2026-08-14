<?php

declare(strict_types=1);

namespace Kode\Bench\Scenario;

use Nyholm\Psr7\ServerRequest;

/**
 * Slim 4 对等框架场景（隔离安装在 benchmarks/peers/slim，不污染框架 vendor）。
 *
 * 镜像 kode 的两条路由：/ping（最小响应）与 /bench/json（DI 等价逻辑 + 50 条记录 JSON）。
 * 用于「同类轻量微框架」的 apples-to-apples 吞吐对比。若未安装（ benchmarks/peers/slim/vendor
 * 不存在），scenario() 返回 null，编排器自动跳过。
 */
final class Slim
{
    public static function available(string $peerRoot): bool
    {
        return is_file($peerRoot . '/vendor/autoload.php');
    }

    /**
     * @return (callable(): void)|null
     */
    public static function scenario(string $peerRoot, string $route): ?callable
    {
        $autoload = $peerRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return null;
        }

        require_once $autoload;

        if (!class_exists(\Slim\Factory\AppFactory::class)) {
            return null;
        }

        \Slim\Factory\AppFactory::setSlimHttpDecoratorsAutomaticDetection(false);
        $app = \Slim\Factory\AppFactory::create();

        $json = static function (array $data) {
            return static function ($request, $response) use ($data) {
                $response->getBody()->write((string) json_encode($data));

                return $response->withHeader('Content-Type', 'application/json');
            };
        };

        $app->get('/ping', $json(['pong' => true]));

        $app->get('/bench/json', $json([
            'framework' => 'slim',
            'now'       => date('c'),
            'items'     => array_map(
                static fn (int $i) => ['id' => $i, 'name' => "item-$i"],
                range(1, 50)
            ),
        ]));

        return static function () use ($app, $route): ?int {
            return $app->handle(new ServerRequest('GET', $route))->getStatusCode();
        };
    }
}
