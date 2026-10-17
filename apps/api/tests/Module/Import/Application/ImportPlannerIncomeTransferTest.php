<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Domain\Plan\ImportPlan;
use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Tests\Module\Import\Support\PlannerTestCase;

final class ImportPlannerIncomeTransferTest extends PlannerTestCase
{
    public function testEachTermOfAnIncomeTransferLineIsATransferToTheMainAccountAndNeverIncome(): void
    {
        // 100 + 50 = 150 withdrawn from Savings A in two movements.
        $this->workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->number('B2', '2500')
            ->text('A3', 'Savings withdrawal')->formula('B3', '100+50', '150')
            ->text('G2', 'Savings A')->number('H2', '200');

        $plan = $this->plan();

        self::assertSame([], self::issues($plan));
        self::assertSame([
            'INCOME 2025-01-31 2500.00 wb:2025:01:INC:salary:1',
            'TRANSFER 2025-01-31 100.00 acc-Savings A>acc-Main current account',
            'TRANSFER 2025-01-31 50.00 acc-Savings A>acc-Main current account',
            'TRANSFER 2025-01-31 200.00 acc-Main current account>acc-Savings A',
        ], self::describe($plan));

        $first = $plan->steps[1];
        $second = $plan->steps[2];
        self::assertInstanceOf(PlannedTransfer::class, $first);
        self::assertInstanceOf(PlannedTransfer::class, $second);
        self::assertSame('wb:2025:01:INC:savings-withdrawal:1', $first->key);
        self::assertSame('wb:2025:01:INC:savings-withdrawal:2', $second->key);
        self::assertSame('Imported from workbook 2025, Janvier B3 (wb:2025:01:INC:savings-withdrawal:1)', $first->note);
        self::assertSame('Savings withdrawal', $first->label);
        self::assertSame(['INCOME' => 1, 'TRANSFER' => 3], self::countByKind($plan));
    }

    public function testAnExplicitDestinationAccountIsUsed(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Voucher top-up')->number('B2', '30');

        self::assertSame(['TRANSFER 2025-01-31 30.00 acc-Savings A>acc-Meal vouchers'], self::describe($this->plan()));
    }

    public function testANegativeTermRejectsTheWholeLineAsNegativeIncomeAtParseTime(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Savings withdrawal')->formula('B2', '100-30', '70');

        $plan = $this->plan();

        self::assertSame(['NEGATIVE_INCOME@Janvier!B2'], self::issues($plan));
        self::assertTrue($plan->hasRejections());
        self::assertSame([], $plan->steps);
    }

    public function testAZeroAmountIsExcludedAsToday(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Savings withdrawal')->number('B2', '0');

        $plan = $this->plan();

        self::assertSame(['ZERO_AMOUNT@Janvier!B2'], self::issues($plan));
        self::assertSame([], $plan->steps);
    }

    public function testAnUnknownSourceAccountIsUnmappedAccount(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Ghost transfer')->number('B2', '10');

        $plan = $this->plan();

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!A2'], self::issues($plan));
        self::assertSame([], $plan->steps);
    }

    public function testTheCurrentMonthIsBookedTodayAndAFutureMonthIsLeftOut(): void
    {
        $this->references->today = '2025-01-15';
        $this->workbook->monthSheet('Janvier')->text('A2', 'Savings withdrawal')->number('B2', '10');
        $this->workbook->monthSheet('Février')->text('A2', 'Savings withdrawal')->number('B2', '20');

        $plan = $this->plan();

        self::assertSame(['TRANSFER 2025-01-15 10.00 acc-Savings A>acc-Main current account'], self::describe($plan));
        self::assertSame(['MONTH_NOT_ENDED@Février!-'], self::issues($plan));
    }

    /**
     * @return array<string, int>
     */
    private static function countByKind(ImportPlan $plan): array
    {
        $counts = [];
        foreach ($plan->steps as $step) {
            $counts[$step->kind()->name] = ($counts[$step->kind()->name] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
