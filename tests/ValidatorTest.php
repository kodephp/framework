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

    /**
     * `min:`/`max:` 遇到**纯数字字符串**必须按字符数判，而不是按数值。
     *
     * 旧实现按 `is_numeric($value)` 自适应选比较方式，而 HTTP 层拿到的 JSON 值常常就是字符串，
     * 于是「长度校验」在特定输入下悄悄换成「大小校验」：
     * `'12'` 过 `min:6`（12 >= 6）= 密码长度策略被一枚两位数字绕过；
     * `'20260101'` 过不了 `max:128`（2.026e7 > 128）= 用日期当标题直接 422；
     * 全数字的 64 位 sha256 同样过不了 `max:64`。判据不能长在「值长什么样」上。
     */
    public function testDigitOnlyStringsAreComparedByLengthNotByValue(): void
    {
        $v = new Validator();

        self::assertNotEmpty(
            $v->validate(['password' => '12'], ['password' => 'required|min:6|max:64']),
            '两位纯数字密码通过了 min:6：长度判据被数值比较顶掉了'
        );
        self::assertSame(
            [],
            $v->validate(['title' => '20260101'], ['title' => 'required|min:2|max:128']),
            '八位数字标题被当成大于 128 的数值拒了'
        );
        self::assertSame(
            [],
            $v->validate(['sha256' => str_repeat('7', 64)], ['sha256' => 'required|min:64|max:64']),
            '全数字的 64 位哈希恰好合规，却按大小被判了超长'
        );
    }

    /**
     * 反面：字段**自己声明**了数值类型（或值本来就是 int/float）时，min/max 仍是数值比较。
     *
     * 这条是上一条的对照 —— 收口不能把「年龄 150 上限」这类真实数值约束顺手改成字符数，
     * 否则 `age => 151` 会因为它只有 3 个字符而被放过。
     */
    public function testFieldsThatDeclareNumericTypeStillCompareNumerically(): void
    {
        $v = new Validator();

        self::assertSame([], $v->validate(['age' => 18], ['age' => 'integer|min:0|max:150']));
        self::assertNotEmpty($v->validate(['age' => 151], ['age' => 'integer|min:0|max:150']));
        self::assertNotEmpty($v->validate(['age' => '151'], ['age' => 'integer|min:0|max:150']));

        self::assertSame([], $v->validate(['price' => '9.5'], ['price' => 'numeric|min:0|max:100']));
        self::assertNotEmpty($v->validate(['price' => '1000'], ['price' => 'numeric|min:0|max:100']));

        // `string` 标记：既验类型，也把 min/max 定在字符数上
        self::assertSame([], $v->validate(['code' => '12345678'], ['code' => 'string|max:64']));
        self::assertNotEmpty($v->validate(['code' => 'abcdefghi'], ['code' => 'string|max:8']));
        // 值不是字符串时只报「类型不对」，不叠一条看不懂的长度错
        $wrong = $v->validate(['code' => 12345678], ['code' => 'string|max:8']);
        self::assertCount(1, $wrong);
        self::assertSame('code', $wrong[0]['field']);
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
