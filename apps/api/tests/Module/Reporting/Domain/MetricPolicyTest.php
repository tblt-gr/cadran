<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Reporting\Domain\InvalidMetricPolicy;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Module\Reporting\Domain\MetricPolicyComparability;
use App\Module\Reporting\Domain\MetricPolicyResolver;
use PHPUnit\Framework\TestCase;

final class MetricPolicyTest extends TestCase
{
    public function testSystemVersionOneExcludesOnlyEmployeeBenefitAccounts(): void
    {
        $policy = MetricPolicy::systemV1();

        self::assertSame(1, $policy->version);
        self::assertSame('Définition de trésorerie', $policy->label);
        self::assertSame([AccountKind::EMPLOYEE_BENEFIT], $policy->cashExcludedAccountKinds);
        self::assertTrue($policy->isExcluded('EMPLOYEE_BENEFIT'));
        self::assertFalse($policy->isExcluded('CURRENT'));
        self::assertNull($policy->createdAt);
        self::assertNull($policy->createdBy);
    }

    public function testAWorkspaceVersionSortsAndTrimsItsDefinition(): void
    {
        $policy = MetricPolicy::create(2, '  Tout compte  ', [AccountKind::SAVINGS, AccountKind::CASH], new \DateTimeImmutable('2026-05-01T00:00:00Z'), 'user');

        self::assertSame('Tout compte', $policy->label);
        self::assertSame([AccountKind::CASH, AccountKind::SAVINGS], $policy->cashExcludedAccountKinds);
    }

    /** @param list<AccountKind> $kinds */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionsAreRefused(int $version, string $label, array $kinds): void
    {
        $this->expectException(InvalidMetricPolicy::class);

        MetricPolicy::create($version, $label, $kinds, new \DateTimeImmutable(), 'user');
    }

    /** @return iterable<string, array{int, string, list<AccountKind>}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'version below 2' => [1, 'Label', []];
        yield 'blank label' => [2, '   ', []];
        yield 'label of 81 characters' => [2, str_repeat('a', 81), []];
        yield 'duplicated kind' => [2, 'Label', [AccountKind::CASH, AccountKind::CASH]];
    }

    public function testALabelOfExactlyEightyCharactersIsAccepted(): void
    {
        self::assertSame(80, mb_strlen(MetricPolicy::create(2, str_repeat('é', 80), [], new \DateTimeImmutable(), 'user')->label));
    }

    public function testNoActivationResolvesEveryMonthToVersionOne(): void
    {
        self::assertSame(1, MetricPolicyResolver::forMonth(null, new \DateTimeImmutable('2026-06-01T00:00:00Z'), []));
        self::assertSame(1, MetricPolicyResolver::forMonth(new \DateTimeImmutable('2026-05-02T00:00:00Z'), new \DateTimeImmutable('2026-06-01T00:00:00Z'), []));
    }

    public function testAClosedMonthKeepsThePolicyActiveAtItsClosingInstant(): void
    {
        $activations = [new MetricPolicyActivation(2, new \DateTimeImmutable('2026-05-10T08:00:00Z'))];
        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        self::assertSame(1, MetricPolicyResolver::forMonth(new \DateTimeImmutable('2026-05-02T00:00:00Z'), $now, $activations));
        self::assertSame(2, MetricPolicyResolver::forMonth(new \DateTimeImmutable('2026-05-12T00:00:00Z'), $now, $activations));
        self::assertSame(2, MetricPolicyResolver::forMonth(new \DateTimeImmutable('2026-05-10T08:00:00Z'), $now, $activations));
    }

    public function testAnOpenMonthUsesThePolicyActiveNowAndIgnoresFutureActivations(): void
    {
        $activations = [
            new MetricPolicyActivation(3, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
            new MetricPolicyActivation(2, new \DateTimeImmutable('2026-05-10T08:00:00Z')),
            new MetricPolicyActivation(1, new \DateTimeImmutable('2026-05-20T08:00:00Z')),
        ];

        self::assertSame(1, MetricPolicyResolver::forMonth(null, new \DateTimeImmutable('2026-06-01T00:00:00Z'), $activations));
        self::assertSame(2, MetricPolicyResolver::forMonth(null, new \DateTimeImmutable('2026-05-15T00:00:00Z'), $activations));
    }

    public function testComparabilityRequiresTheSameVersion(): void
    {
        self::assertSame(MetricPolicyComparability::COMPARABLE, MetricPolicyComparability::compare(2, 2));
        self::assertSame(MetricPolicyComparability::NOT_COMPARABLE, MetricPolicyComparability::compare(1, 2));
    }
}
