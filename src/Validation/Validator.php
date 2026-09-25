<?php

declare(strict_types=1);

namespace Kode\Framework\Validation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * 验证器（基于 Symfony Validator）
 *
 * 提供简洁的「规则字符串」写法，内部翻译为 Symfony Constraint，
 * 既保留了 Symfony 强大的校验能力，又让日常校验足够顺手。
 *
 * 规则示例：
 *   [
 *     'name'  => 'required|min:2|max:50',        // 字符串 => 按字符数
 *     'email' => 'required|email',
 *     'age'   => 'nullable|integer|min:0|max:150', // 声明整数 => 按数值
 *     'price' => 'required|numeric|min:0',
 *     'code'  => 'required|string|max:64',       // 明说「这是串」，纯数字也不会被当成数值
 *     'role'  => 'required|in:admin,user',
 *     'site'  => 'nullable|url',
 *   ]
 *
 * 也支持在 DTO 上使用 Symfony 的 #[Assert\*] 属性，调用 validateObject()。
 */
final class Validator
{
    private ValidatorInterface $sf;

    public function __construct()
    {
        $this->sf = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * 校验数组数据。
     *
     * @param array<string, mixed>        $data    待校验数据
     * @param array<string, string>       $rules   字段 => 规则串
     * @param array<string, string>       $messages 字段 => 自定义错误提示
     * @return array<int, array{field:string, message:string}>
     */
    public function validate(array $data, array $rules, array $messages = []): array
    {
        $violations = [];

        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $constraints = $this->parseRules($ruleStr, $value);

            foreach ($this->sf->validate($value, $constraints) as $violation) {
                $violations[] = [
                    'field' => $field,
                    'message' => $messages[$field] ?? (string) $violation->getMessage(),
                ];
            }
        }

        return $violations;
    }

    /**
     * 校验对象（支持 Symfony 属性约束）。
     *
     * @return array<int, array{field:string, message:string}>
     */
    public function validateObject(object $object): array
    {
        $violations = [];
        foreach ($this->sf->validate($object) as $violation) {
            $violations[] = [
                'field' => (string) $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return $violations;
    }

    /**
     * 将用户友好的规则串翻译为 Symfony Constraint 列表。
     *
     * min:/max: 的比较方式由**字段自己声明的类型**决定，而不是由值碰巧长什么样决定：
     *  - 规则里写了 `integer` / `numeric`，或值本来就是 int/float → 数值比较
     *    （GreaterThanOrEqual / LessThanOrEqual）
     *  - 其余一律按字符数（Length）
     *
     * 旧实现看的是 `is_numeric($value)`，而 HTTP 层拿到的 JSON 值常常就是字符串，
     * 于是同一份规则会在特定输入下悄悄换成另一种判据：
     * `'12'` 过 `min:6`（12 >= 6，密码长度策略被一枚两位数字绕过）、
     * `'20260101'` 过不了 `max:128`（数值大于 128 就当「超长」拒掉）、
     * 全数字的 64 位 sha256 过不了 `max:64`。反过来 `'151'` 这种「该拒的」也可能被放过。
     * 需要按大小判的字段请用 `integer` / `numeric` 明说；需要按字符数判的可以直接写 `string`。
     *
     * @param mixed $value 当前字段值，用于推断 min/max 约束类型
     * @return list<Assert\Constraint>
     */
    private function parseRules(string $ruleStr, mixed $value): array
    {
        $constraints = [];
        $required = false;
        $nullable = false;

        $parts = array_map('trim', explode('|', $ruleStr));
        // 先扫一遍再定 min/max 的语义：`integer|min:0` 与 `min:0|integer` 是同一份契约，
        // 按出现顺序判会让写法影响比较方式。
        $declaresNumeric = array_intersect($parts, ['integer', 'numeric']) !== [];
        $declaresString = array_intersect($parts, ['string']) !== [];
        $isNumeric = $declaresNumeric || is_int($value) || is_float($value);
        // 字段声明了自己是字符串、值却不是时，只让 Type('string') 报「类型不对」，
        // 不要再叠一条按大小/长度算出来的错（两条同时出，第二条纯属误导）。
        $skipMinMax = $declaresString && !is_string($value);

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part === 'required') {
                $required = true;
                continue;
            }
            if ($part === 'nullable') {
                $nullable = true;
                continue;
            }
            if ($part === 'email') {
                $constraints[] = new Assert\Email();
                continue;
            }
            if ($part === 'url') {
                $constraints[] = new Assert\Url();
                continue;
            }
            if ($part === 'integer') {
                $constraints[] = new Assert\Type('integer');
                continue;
            }
            if ($part === 'string') {
                $constraints[] = new Assert\Type('string');
                continue;
            }
            if ($part === 'numeric') {
                $constraints[] = new Assert\Type('numeric');
                continue;
            }
            if ($part === 'boolean' || $part === 'bool') {
                $constraints[] = new Assert\Type('bool');
                continue;
            }
            if (str_starts_with($part, 'min:')) {
                if ($skipMinMax) {
                    continue;
                }
                $n = (int) substr($part, 4);
                $constraints[] = $isNumeric
                    ? new Assert\GreaterThanOrEqual($n)
                    : new Assert\Length(['min' => $n]);
                continue;
            }
            if (str_starts_with($part, 'max:')) {
                if ($skipMinMax) {
                    continue;
                }
                $n = (int) substr($part, 4);
                $constraints[] = $isNumeric
                    ? new Assert\LessThanOrEqual($n)
                    : new Assert\Length(['max' => $n]);
                continue;
            }
            if (str_starts_with($part, 'length:')) {
                $constraints[] = new Assert\Length(['max' => (int) substr($part, 7)]);
                continue;
            }
            if (str_starts_with($part, 'in:')) {
                $constraints[] = new Assert\Choice(['choices' => explode(',', substr($part, 3))]);
                continue;
            }
            if (str_starts_with($part, 'regex:')) {
                $pattern = trim(substr($part, 6), '/');
                $constraints[] = new Assert\Regex('/' . $pattern . '/');
                continue;
            }
        }

        if ($required) {
            $constraints[] = new Assert\NotNull();
            $constraints[] = new Assert\NotBlank();
        } elseif ($nullable) {
            // 允许 null 透传，不强制 NotNull
            $constraints[] = new Assert\AtLeastOneOf([
                new Assert\IsNull(),
                new Assert\NotNull(),
            ]);
        }

        return $constraints;
    }
}
