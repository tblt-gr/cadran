<?php

declare(strict_types=1);

namespace App\Tests\Module\Budget\Domain\EmergencyFund;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\InvalidEmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\ReferenceMode;
use App\Module\Budget\Domain\EmergencyFund\TargetMode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmergencyFundPolicyTest extends TestCase
{
    private const string ACTOR = '00000000-0000-7000-8000-000000000001';

    public function testTheDefaultPolicyReadsTheEssentialAxisOverThePreviousYearForSixMonths(): void
    {
        $policy = EmergencyFundPolicy::default(self::workspace());

        self::assertSame(EssentialMode::AXIS, $policy->essentialMode);
        self::assertSame([], $policy->essentialCategoryIds);
        self::assertSame(ReferenceMode::PREVIOUS_YEAR, $policy->referenceMode);
        self::assertNull($policy->trailingMonths);
        self::assertSame('6', $policy->targetMonths->toString());
        self::assertSame(0, $policy->version);
        self::assertSame(31, EmergencyFundPolicy::STALE_VALUATION_DAYS);
    }

    public function testAFixedAmountAndAnIncomeRatioAreTwoMoreWaysToSetTheTarget(): void
    {
        $default = EmergencyFundPolicy::default(self::workspace());
        $now = new \DateTimeImmutable('2026-09-29T10:00:00+00:00');
        $amount = $default->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), $now, self::ACTOR, TargetMode::AMOUNT, DecimalValue::fromString('15000'), AssetCode::fromString('EUR'));
        $ratio = $default->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), $now, self::ACTOR, TargetMode::INCOME_RATIO, null, null, DecimalValue::fromString('0.5'));

        self::assertSame(TargetMode::MONTHS, $default->targetMode);
        self::assertSame(['targetMode', 'targetAmount'], $default->changedFields($amount));
        self::assertSame(['targetMode', 'targetRatio'], $default->changedFields($ratio));
    }

    /** @return iterable<string, array{TargetMode, ?string, ?string, ?string, string}> */
    public static function invalidTargets(): iterable
    {
        yield 'amount without asset' => [TargetMode::AMOUNT, '100', null, null, 'invalid_target_amount'];
        yield 'zero amount' => [TargetMode::AMOUNT, '0', 'EUR', null, 'invalid_target_amount'];
        yield 'amount with a ratio' => [TargetMode::AMOUNT, '100', 'EUR', '0.5', 'invalid_target_amount'];
        yield 'ratio missing' => [TargetMode::INCOME_RATIO, null, null, null, 'invalid_target_ratio'];
        yield 'ratio above one' => [TargetMode::INCOME_RATIO, null, null, '1.1', 'invalid_target_ratio'];
        yield 'ratio with seven decimals' => [TargetMode::INCOME_RATIO, null, null, '0.1234567', 'invalid_target_ratio'];
        yield 'months with an amount' => [TargetMode::MONTHS, '100', 'EUR', null, 'invalid_emergency_fund_policy'];
    }

    #[DataProvider('invalidTargets')]
    public function testAnIncoherentTargetIsRefused(TargetMode $mode, ?string $amount, ?string $asset, ?string $ratio, string $code): void
    {
        try {
            EmergencyFundPolicy::default(self::workspace())->revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString('6'), new \DateTimeImmutable('2026-09-29T10:00:00+00:00'), self::ACTOR, $mode, null === $amount ? null : DecimalValue::fromString($amount), null === $asset ? null : AssetCode::fromString($asset), null === $ratio ? null : DecimalValue::fromString($ratio));
            self::fail('The target should have been refused.');
        } catch (InvalidEmergencyFundPolicy $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    public function testThePreviousYearReferenceIsTheCalendarYearBeforeTheAsOfDate(): void
    {
        $period = EmergencyFundPolicy::default(self::workspace())->referencePeriod(new CalendarMonth(2026, 1));

        self::assertSame(['2025-01', '2025-12', 12], [$period->start->key(), $period->end->key(), $period->expectedMonths]);
    }

    public function testTheTrailingReferenceEndsTheMonthBeforeTheAsOfMonthAcrossAYearBoundary(): void
    {
        $policy = self::revise(EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, 3, '6');

        $period = $policy->referencePeriod(new CalendarMonth(2026, 2));

        self::assertSame(['2025-11', '2026-01', 3], [$period->start->key(), $period->end->key(), $period->expectedMonths]);
    }

    public function testARevisionBumpsTheVersionAndRecordsItsAuthor(): void
    {
        $policy = self::revise(EssentialMode::CATEGORIES, ['00000000-0000-7000-8000-000000000201'], ReferenceMode::TRAILING_MONTHS, 24, '60');

        self::assertSame(1, $policy->version);
        self::assertSame(self::ACTOR, $policy->updatedBy);
        self::assertSame(['essentialMode', 'essentialCategoryIds', 'referenceMode', 'trailingMonths', 'targetMonths'], EmergencyFundPolicy::default(self::workspace())->changedFields($policy));
    }

    public function testNumericallyEqualTargetsAreNotAChange(): void
    {
        $policy = self::revise(EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, '6.0');

        self::assertSame([], EmergencyFundPolicy::default(self::workspace())->changedFields($policy));
    }

    /** @return iterable<string, array{EssentialMode, list<string>, ReferenceMode, ?int, string}> */
    public static function invalidPolicies(): iterable
    {
        $id = '00000000-0000-7000-8000-000000000201';
        yield 'negative target' => [EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, '-0.5'];
        yield 'target above sixty' => [EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, '60.1'];
        yield 'two target decimals' => [EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, '6.25'];
        yield 'zero trailing months' => [EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, 0, '6'];
        yield 'twenty-five trailing months' => [EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, 25, '6'];
        yield 'trailing mode without months' => [EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, null, '6'];
        yield 'previous year with months' => [EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, 3, '6'];
        yield 'axis with categories' => [EssentialMode::AXIS, [$id], ReferenceMode::PREVIOUS_YEAR, null, '6'];
        yield 'categories without any' => [EssentialMode::CATEGORIES, [], ReferenceMode::PREVIOUS_YEAR, null, '6'];
        yield 'duplicate categories' => [EssentialMode::CATEGORIES, [$id, $id], ReferenceMode::PREVIOUS_YEAR, null, '6'];
        yield 'fifty-one categories' => [EssentialMode::CATEGORIES, self::ids(51), ReferenceMode::PREVIOUS_YEAR, null, '6'];
    }

    /** @param list<string> $categories */
    #[DataProvider('invalidPolicies')]
    public function testAnOutOfBoundsPolicyIsRefused(EssentialMode $mode, array $categories, ReferenceMode $reference, ?int $trailing, string $target): void
    {
        $this->expectException(InvalidEmergencyFundPolicy::class);

        self::revise($mode, $categories, $reference, $trailing, $target);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        self::assertSame('0', self::revise(EssentialMode::AXIS, [], ReferenceMode::TRAILING_MONTHS, 1, '0')->targetMonths->toString());
        self::assertSame('60.0', self::revise(EssentialMode::CATEGORIES, self::ids(50), ReferenceMode::TRAILING_MONTHS, 24, '60.0')->targetMonths->toString());
    }

    /** @param list<string> $categories */
    private static function revise(EssentialMode $mode, array $categories, ReferenceMode $reference, ?int $trailing, string $target): EmergencyFundPolicy
    {
        return EmergencyFundPolicy::default(self::workspace())->revise($mode, $categories, $reference, $trailing, DecimalValue::fromString($target), new \DateTimeImmutable('2026-09-29T10:00:00+00:00'), self::ACTOR);
    }

    /** @return list<string> */
    private static function ids(int $count): array
    {
        return array_map(static fn (int $index): string => sprintf('00000000-0000-7000-8000-%012d', $index), range(1, $count));
    }

    private static function workspace(): WorkspaceScope
    {
        return WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1');
    }
}
