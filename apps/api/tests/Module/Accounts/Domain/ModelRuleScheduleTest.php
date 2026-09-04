<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Catalog\Domain\RuleKind;
use PHPUnit\Framework\TestCase;

/**
 * Two periods of the same kind covering the same day would give "the rate on
 * 12 March" two answers, and the model would silently pick one. Recording a
 * revision therefore closes what it supersedes instead of overwriting it.
 */
final class ModelRuleScheduleTest extends TestCase
{
    public function testTwoPeriodsOfTheSameKindCannotCoverTheSameDay(): void
    {
        $this->expectException(InvalidProductModel::class);

        new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01', '2026-12-31'),
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-06-01', '2026-06-30'),
        ]);
    }

    public function testTwoPeriodsOfDifferentKindsMayCoverTheSameDay(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b2', '30000', '2026-01-01'),
        ]);

        self::assertCount(2, $schedule->effectiveOn(ProductModelFixture::day('2026-03-12')));
    }

    public function testPeriodsAreOrderedByRuleKindThenByStartDate(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b3', '2026-07-01'),
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-01-01', '2026-06-30'),
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '30000', '2026-01-01'),
        ]);

        self::assertSame(
            [RuleKind::BALANCE_CEILING, RuleKind::ANNUAL_RATE, RuleKind::ANNUAL_RATE],
            array_map(static fn ($rule) => $rule->kind, $schedule->rules),
        );
        self::assertSame('2026-01-01', $schedule->rules[1]->period->validFrom->format('Y-m-d'));
    }

    public function testAppendingClosesTheOpenEndedPeriodItSupersedes(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]);

        $revised = $schedule->appended(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-07-01'),
        );

        self::assertSame('2026-06-30', $revised->rules[0]->period->validTo?->format('Y-m-d'));
        self::assertSame(
            '2026-01-01',
            $revised->rules[0]->period->validFrom->format('Y-m-d'),
            'Superseding records when a period stopped applying; it never moves when it started.',
        );
    }

    public function testAppendingIntoAClosedPeriodIsRefusedRatherThanReconciled(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01', '2026-12-31'),
        ]);

        $this->expectException(InvalidProductModel::class);

        $schedule->appended(ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-07-01'));
    }

    public function testAppendingOnTheSameStartDateIsRefused(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]);

        // Closing the earlier period the day before would end it before it
        // started, so the contradiction is refused instead of being folded away.
        $this->expectException(InvalidProductModel::class);

        $schedule->appended(ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-01-01'));
    }

    public function testAPeriodOfAnotherKindIsLeftUntouchedWhenARateIsRevised(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b2', '30000', '2026-01-01'),
        ]);

        $revised = $schedule->appended(
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b3', '2026-07-01'),
        );

        $ceiling = $revised->rules[0];
        self::assertSame(RuleKind::BALANCE_CEILING, $ceiling->kind);
        self::assertNull($ceiling->period->validTo);
    }

    public function testADayBeforeEveryPeriodResolvesToNothingRatherThanToAnEmptyValue(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]);

        self::assertSame([], $schedule->effectiveOn(ProductModelFixture::day('2025-12-31')));
    }

    public function testACopyKeepsTheDatesAndTakesFreshIdentifiers(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01', '2026-06-30'),
        ]);

        $copy = $schedule->copyWithIds(['00000000-0000-7000-8000-0000000000c1']);

        self::assertSame('00000000-0000-7000-8000-0000000000c1', $copy->rules[0]->id);
        self::assertSame('2026-01-01', $copy->rules[0]->period->validFrom->format('Y-m-d'));
        self::assertSame('2026-06-30', $copy->rules[0]->period->validTo?->format('Y-m-d'));
    }

    public function testACopyNeedsOneIdentifierPerPeriod(): void
    {
        $schedule = new ModelRuleSchedule([
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
        ]);

        $this->expectException(InvalidProductModel::class);

        $schedule->copyWithIds([]);
    }
}
