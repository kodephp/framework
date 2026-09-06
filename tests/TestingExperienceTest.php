<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

/**
 * 测试体验件：patch() 动词与 assertJson/assertJsonPath/assertHeader 断言。
 */
final class TestingExperienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(self::SKELETON_ROOT);
    }

    public function testPatchVerbSendsJsonBody(): void
    {
        $this->app()->http()->patch('/_dx/echo', static fn($req) => \Kode\Framework\Http\Resp::json(
            ['method' => $req->getMethod(), 'a' => $req->getParsedBody()['a'] ?? null]
        ));

        $this->patch('/_dx/echo', ['a' => 1])
            ->assertStatus(200)
            ->assertJsonPath('method', 'PATCH')
            ->assertJsonPath('a', 1);
    }

    public function testAssertJsonSubsetAndHeader(): void
    {
        $this->get('/ping')
            ->assertStatus(200)
            ->assertJson(['pong' => true])
            ->assertHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
