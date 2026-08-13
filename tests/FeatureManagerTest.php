<?php

declare(strict_types=1);

namespace Kode\Framework\Tests;

use Kode\Core\Config\Config;
use Kode\Framework\Feature\Attributes\Feature;
use Kode\Framework\Feature\FeatureAttributeReader;
use Kode\Framework\Feature\FeatureManager;
use PHPUnit\Framework\TestCase;

/**
 * FeatureManager + FeatureAttributeReader 单元验证。
 */
final class FeatureManagerTest extends TestCase
{
    private function manager(array $feature = []): FeatureManager
    {
        $config = new Config();
        $config->set('feature', $feature);

        return new FeatureManager($config);
    }

    public function testUnknownFlagFallsBackToDefault(): void
    {
        $m = $this->manager(['default' => false]);
        self::assertFalse($m->isEnabled('nope'));

        $m2 = $this->manager(['default' => true]);
        self::assertTrue($m2->isEnabled('nope'));
    }

    public function testEnabledTrueRollout100IsOn(): void
    {
        $m = $this->manager(['flags' => ['x' => ['enabled' => true, 'rollout' => 100]]]);
        self::assertTrue($m->isEnabled('x'));
    }

    public function testEnabledFalseForcesOffRegardlessOfRollout(): void
    {
        $m = $this->manager(['flags' => ['x' => ['enabled' => false, 'rollout' => 100]]]);
        self::assertFalse($m->isEnabled('x'));
    }

    public function testRollout0IsOffEvenWhenEnabled(): void
    {
        $m = $this->manager(['flags' => ['x' => ['enabled' => true, 'rollout' => 0]]]);
        self::assertFalse($m->isEnabled('x'));
    }

    public function testRolloutBucketingIsStableAndDeterministic(): void
    {
        $m = $this->manager(['flags' => ['x' => ['enabled' => true, 'rollout' => 50]]]);

        // 同一 key 结果稳定
        self::assertSame($m->isEnabled('x', 'user:1'), $m->isEnabled('x', 'user:1'));

        // 与公式一致：bucket(name:key) = crc32 % 100 < rollout
        $hit = crc32('x:user:1') % 100 < 50;
        self::assertSame($hit, $m->isEnabled('x', 'user:1'));

        // 不同 key 可能不同（统计上约半数命中）
        $hits = 0;
        for ($i = 0; $i < 1000; $i++) {
            if ($m->isEnabled('x', 'user:' . $i)) {
                $hits++;
            }
        }
        self::assertGreaterThan(300, $hits);
        self::assertLessThan(700, $hits);
    }

    public function testResolverOverridesConfig(): void
    {
        $m = $this->manager(['flags' => ['x' => ['enabled' => true, 'rollout' => 100]]]);

        // resolver 返回 null → 走 config（开启）
        $m->registerResolver(static fn(string $name, ?string $key): ?bool => null);
        self::assertTrue($m->isEnabled('x'));

        // resolver 返回非 null → 短路覆盖
        $m->registerResolver(static fn(string $name, ?string $key): ?bool => $name === 'x' ? false : null);
        self::assertFalse($m->isEnabled('x'));
    }

    public function testStatusAndAll(): void
    {
        $m = $this->manager([
            'default' => false,
            'flags'   => ['x' => ['enabled' => true, 'rollout' => 30]],
        ]);

        $status = $m->status('x', 'user:1');
        self::assertSame('x', $status['name']);
        self::assertTrue($status['configured']);
        self::assertSame(30, $status['rollout']);
        self::assertIsBool($status['enabled']);

        $all = $m->all();
        self::assertArrayHasKey('x', $all);
        self::assertArrayNotHasKey('unknown', $all);
    }

    public function testAttributeReaderReadsClassAndMethodLevel(): void
    {
        $reader = new FeatureAttributeReader();

        // 类级 #[Feature('global')] 对所有方法生效（含无方法级声明者）
        self::assertSame(['flag' => 'global', 'fallback' => 404], $reader->read(FeatureAttrTarget::class, 'noAttr'));

        // 方法级
        $entry = $reader->read(FeatureAttrTarget::class, 'beta');
        self::assertSame(['flag' => 'beta-search', 'fallback' => 403], $entry);

        // 类级默认（本方法无方法级覆盖）
        $entry2 = $reader->read(FeatureAttrTarget::class, 'classScoped');
        self::assertSame(['flag' => 'global', 'fallback' => 404], $entry2);

        // 方法级覆盖类级
        $entry3 = $reader->read(FeatureAttrTarget::class, 'override');
        self::assertSame(['flag' => 'override-flag', 'fallback' => 403], $entry3);
    }
}

#[Feature('global')]
class FeatureAttrTarget
{
    public function noAttr(): void
    {
    }

    #[Feature('beta-search', fallback: 403)]
    public function beta(): void
    {
    }

    public function classScoped(): void
    {
    }

    #[Feature('override-flag', fallback: 403)]
    public function override(): void
    {
    }
}
