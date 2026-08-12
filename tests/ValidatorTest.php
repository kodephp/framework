<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Framework\Http\Controller;
use Kode\Http\Response;
use Kode\Framework\Validation\ValidationException;
use Kode\Framework\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * 参数校验（Symfony Validator 封装）单元测试。
 */
final class ValidatorTest extends TestCase
{
    public function testPassesWhenValid(): void
    {
        $v = new Validator();
        $errors = $v->validate(
            ['name' => 'Kode', 'email' => 'a@b.com', 'age' => 18],
            ['name' => 'required|min:2|max:50', 'email' => 'required|email', 'age' => 'nullable|integer|min:0|max:150'],
        );

        self::assertSame([], $errors);
    }

    public function testFailsOnMissingAndBadFormat(): void
    {
        $v = new Validator();
        $errors = $v->validate(
            ['name' => '', 'email' => 'not-an-email'],
            ['name' => 'required|min:2', 'email' => 'required|email'],
        );

        self::assertNotEmpty($errors);
        $fields = array_column($errors, 'field');
        self::assertContains('name', $fields);
        self::assertContains('email', $fields);
    }

    public function testChoiceRule(): void
    {
        $v = new Validator();
        $errors = $v->validate(['role' => 'guest'], ['role' => 'required|in:admin,user']);
        self::assertNotEmpty($errors);

        $ok = $v->validate(['role' => 'admin'], ['role' => 'required|in:admin,user']);
        self::assertSame([], $ok);
    }

    public function testControllerJsonErrorProduceStandardResponse(): void
    {
        $controller = new class extends Controller {
            public function jsonRun(): \Kode\Http\Response
            {
                return $this->json(['id' => 1]);
            }

            public function errorRun(): \Kode\Http\Response
            {
                return $this->error('bad', 400);
            }
        };

        $ok = json_decode((string) $controller->jsonRun()->getBody(), true);
        self::assertSame(['id' => 1], $ok);

        $fail = json_decode((string) $controller->errorRun()->getBody(), true);
        self::assertSame('bad', $fail['message']);
        self::assertSame(400, $controller->errorRun()->getStatusCode());
    }

    public function testValidationExceptionCarriesErrors(): void
    {
        $errors = [['field' => 'email', 'message' => '非法邮箱']];
        $e = new ValidationException($errors);

        self::assertSame($errors, $e->errors());
        self::assertSame(422, $e->getCode());
    }
}
