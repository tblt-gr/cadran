<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Tests\Module\Import\Support\PlannerTestCase;

final class ImportPlannerTest extends PlannerTestCase
{
    public function testAMonthBecomesRecordsAtTheLastDayInTheDocumentedOrder(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->number('B2', '2500')
            ->text('C2', 'Rent')->number('D2', '800')
            // Three purchases typed as terms of one cell: 12.50 + 48.20 + 7.90 = 68.60.
            ->text('E2', 'Food')->formula('F2', '12.5+48.2+7.9', '68.6')
            ->text('G2', 'Savings A')->number('H2', '200')
            ->text('J2', 'Main account')->number('K2', '1000')->number('L2', '1431.4')
            ->text('J3', 'Savings A')->number('K3', '0')->number('L3', '200');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'OPENING_ADJUSTMENT 2024-12-31 1000.00 wb:2025:01:OPEN:main-account:1',
            'INCOME 2025-01-31 2500.00 wb:2025:01:INC:salary:1',
            'EXPENSE 2025-01-31 -800.00 wb:2025:01:FIX:rent:1',
            'EXPENSE 2025-01-31 -12.50 wb:2025:01:VAR:food:1',
            'EXPENSE 2025-01-31 -48.20 wb:2025:01:VAR:food:2',
            'EXPENSE 2025-01-31 -7.90 wb:2025:01:VAR:food:3',
            'TRANSFER 2025-01-31 200.00 acc-Main current account>acc-Savings A',
            'BALANCE 2025-01-31 acc-Main current account 1431.40',
            'BALANCE 2025-01-31 acc-Savings A 200.00',
        ], self::describe($plan));
    }

    public function testMonthsAreOrderedWhateverTheSheetOrderAndDatesAreTheirLastDay(): void
    {
        $this->workbook->monthSheet('Mars')->text('A2', 'Salary')->number('B2', '100');
        $this->workbook->monthSheet('Février')->text('A2', 'Salary')->number('B2', '200');

        $plan = $this->plan(year: 2024);

        // 2024 is a leap year: February ends on the 29th.
        self::assertSame([
            'INCOME 2024-02-29 200.00 wb:2024:02:INC:salary:1',
            'INCOME 2024-03-31 100.00 wb:2024:03:INC:salary:1',
        ], self::describe($plan));
    }

    public function testATransactionCarriesItsCategoryAccountAndTheBlockAxis(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('C2', 'Rent')->number('D2', '800')
            ->text('E2', 'Fuel')->number('F2', '40')
            ->text('E3', 'Food')->number('F3', '10');

        $plan = $this->plan();
        [$rent, $fuel, $food] = [self::transactionAt($plan, 0), self::transactionAt($plan, 1), self::transactionAt($plan, 2)];

        // Rent and Food have no default axis, so the block of origin is the axis; Fuel keeps its own.
        self::assertSame(['FIXED'], $rent->analyticAxes);
        self::assertNull($fuel->analyticAxes);
        self::assertSame(['VARIABLE'], $food->analyticAxes);
        self::assertSame('cat-Housing/Rent', $rent->categoryId);
        self::assertSame('acc-Main current account', $rent->accountId);
        self::assertSame('Rent', $rent->rawLabel);
        self::assertSame('Imported from workbook 2025, Janvier D2', $rent->note);
    }

    public function testALabelOf300CharactersFitsTheRawLabelAndTheSourceReference(): void
    {
        $label = rtrim(str_repeat('Very long label ', 19)); // 303 characters
        $this->workbook->monthSheet('Janvier')->text('E2', $label)->number('F2', '10')->text('G2', $label)->number('H2', '5');
        $mapping = str_replace("variable:\n", "variable:\n  \"".$label."\": { category: \"Food/Groceries\" }\n", self::MAPPING);
        $mapping = str_replace("savings:\n", "savings:\n  \"".$label."\": \"Savings A\"\n", $mapping);

        $plan = $this->plan($mapping);

        self::assertSame([], self::issues($plan));
        $expense = self::transactionAt($plan, 0);
        self::assertSame(140, mb_strlen($expense->rawLabel));
        self::assertStringStartsWith('Very long label', $expense->rawLabel);
        self::assertLessThanOrEqual(128, strlen($expense->sourceRef->toString()));
        self::assertInstanceOf(PlannedTransfer::class, $plan->steps[1]);
        self::assertSame(140, mb_strlen($plan->steps[1]->label));
    }

    public function testALineMayTargetAnotherAccount(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Meal vouchers')->number('B2', '120');

        $step = self::transactionAt($this->plan(), 0);

        self::assertSame('acc-Meal vouchers', $step->accountId);
        self::assertSame('cat-Income/Meals', $step->categoryId);
    }

    public function testARefundIsLinkedToTheLargestExpenseOfTheSameLine(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('E2', 'Food')->formula('F2', '10+3-2', '11')
            ->text('E3', 'Food')->number('F3', '50');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'EXPENSE 2025-01-31 -10.00 wb:2025:01:VAR:food:1',
            'EXPENSE 2025-01-31 -3.00 wb:2025:01:VAR:food:2',
            'EXPENSE 2025-01-31 -50.00 wb:2025:01:VAR:food:4',
            'REFUND 2025-01-31 2.00 of wb:2025:01:VAR:food:1',
        ], self::describe($plan));
    }

    public function testARefundFallsBackToTheLargestExpenseOfTheSameCategoryOnAnotherLine(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('E2', 'Snacks')->number('F2', '-4')
            ->text('E3', 'Food')->formula('F3', '9+30', '39');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame('REFUND 2025-01-31 4.00 of wb:2025:01:VAR:food:2', self::describe($plan)[2]);
    }

    public function testARefundOnEqualAmountsLinksTheEarliestExpense(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Food')->formula('F2', '10+10-4', '16');

        self::assertSame('REFUND 2025-01-31 4.00 of wb:2025:01:VAR:food:1', self::describe($this->plan())[2]);
    }

    public function testARefundThatExceedsTheRemainingCapIsUnlinkable(): void
    {
        // 10 spent, then 6 and 6 given back: the second refund exceeds the 4 left.
        $this->workbook->monthSheet('Janvier')->text('E2', 'Food')->formula('F2', '10-6-6', '-2');

        self::assertSame(['UNLINKABLE_REFUND@Janvier!F2'], self::issues($this->plan()));
    }

    public function testARefundBiggerThanEveryExpenseIsUnlinkable(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Food')->formula('F2', '5-20', '-15');

        self::assertSame(['UNLINKABLE_REFUND@Janvier!F2'], self::issues($this->plan()));
    }

    public function testARefundWithoutAnyExpenseOfItsCategoryThisMonthIsUnlinkable(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('E2', 'Fuel')->number('F2', '-3')
            ->text('E3', 'Food')->number('F3', '10');

        self::assertSame(['UNLINKABLE_REFUND@Janvier!F2'], self::issues($this->plan()));
    }

    public function testARefundNeverLinksAnExpenseOfAnotherMonth(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Food')->number('F2', '10');
        $this->workbook->monthSheet('Février')->text('E2', 'Food')->number('F2', '-3');

        self::assertSame(['UNLINKABLE_REFUND@Février!F2'], self::issues($this->plan()));
    }

    public function testAnIncomeLineThatIsNegativeIsRejectedAtParseTimeAndNothingIsPlannedFromIt(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '-5');

        $plan = $this->plan();

        self::assertSame(['NEGATIVE_INCOME@Janvier!B2'], self::issues($plan));
        self::assertTrue($plan->hasRejections());
    }

    public function testAnUnmappedLabelIsRejectedAtItsLabelCell(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('E2', 'Gym')->number('F2', '30')
            ->text('A2', 'Bonus')->number('B2', '30');

        self::assertSame(['UNMAPPED_LABEL@Janvier!A2', 'UNMAPPED_LABEL@Janvier!E2'], self::issues($this->plan()));
    }

    public function testALabelStillNullInTheMappingFileIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Gym')->number('F2', '30');

        $plan = $this->plan(str_replace("variable:\n", "variable:\n  \"Gym\": null\n", self::MAPPING));

        self::assertSame(['UNMAPPED_LABEL@Janvier!E2'], self::issues($plan));
    }

    public function testACategoryThatDoesNotExistOrHasTheWrongTypeLeavesTheLabelUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('E2', 'Gym')->number('F2', '30')
            ->text('E3', 'Pool')->number('F3', '30');
        $mapping = str_replace("variable:\n", "variable:\n  \"Gym\": { category: \"Nope/Missing\" }\n  \"Pool\": { category: \"Income/Salary\" }\n", self::MAPPING);

        self::assertSame(['UNMAPPED_LABEL@Janvier!E2', 'UNMAPPED_LABEL@Janvier!E3'], self::issues($this->plan($mapping)));
    }

    public function testAnAccountThatIsNotInTheWorkspaceIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Gym')->number('F2', '30');
        $mapping = str_replace("variable:\n", "variable:\n  \"Gym\": { category: \"Food/Groceries\", account: \"Ghost\" }\n", self::MAPPING);

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!E2'], self::issues($this->plan($mapping)));
    }

    public function testAnExpenseCannotTargetAPortfolioAccount(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Gym')->number('F2', '30');
        $mapping = str_replace("variable:\n", "variable:\n  \"Gym\": { category: \"Food/Groceries\", account: \"PEA account\" }\n", self::MAPPING);

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!E2'], self::issues($this->plan($mapping)));
    }

    public function testAMissingMainAccountIsRejectedOnceWhateverTheNumberOfLines(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '1')->text('C2', 'Rent')->number('D2', '2');

        self::assertSame(['UNMAPPED_ACCOUNT@-!-'], self::issues($this->plan(str_replace('main_account: "Main current account"', 'main_account: null', self::MAPPING))));
    }

    public function testAnIgnoredLabelIsCountedAndNeverPlanned(): void
    {
        $this->workbook->monthSheet('Janvier')->text('E2', 'Old line')->number('F2', '30');

        $plan = $this->plan(self::MAPPING."\nignore:\n  \"Old line\": \"Duplicate of another line\"\n");

        self::assertSame([], $plan->issues);
        self::assertSame([], $plan->steps);
        self::assertSame(1, $plan->ignoredLines);
    }

    public function testAnAccountOpenedAfterTheDateRejectsTheRecord(): void
    {
        $this->references->withAccount('Main current account', openedOn: '2025-02-01');
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');

        self::assertSame(['ACCOUNT_OPENED_AFTER_DATE@Janvier!B2'], self::issues($this->plan()));
    }

    public function testAClosedMonthIsRejectedOnceForTheSheet(): void
    {
        $this->references->closedMonths = ['2025-01'];
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100')->text('C2', 'Rent')->number('D2', '50');

        self::assertSame(['PERIOD_CLOSED@Janvier!-'], self::issues($this->plan()));
    }

    public function testAYearAlreadyImportedIsRefusedBeforeAnythingIsPlanned(): void
    {
        $this->references->importedYears = [2025];
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');

        $plan = $this->plan();

        self::assertSame(['YEAR_ALREADY_IMPORTED@-!-'], self::issues($plan));
        self::assertSame([], $plan->steps);
    }

    public function testALaterYearAlreadyImportedIsRefused(): void
    {
        $this->references->importedYears = [2026];
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');

        self::assertSame(['LATER_YEAR_ALREADY_IMPORTED@-!-'], self::issues($this->plan()));
    }

    public function testAnEarlierImportedYearDoesNotBlockTheYear(): void
    {
        $this->references->importedYears = [2023, 2024];
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');

        self::assertSame([], self::issues($this->plan()));
    }

    public function testParserRejectionsAreKeptAndEveryMappingRejectionIsAddedToThem(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->formula('B2', 'B3+1', '5')
            ->text('E2', 'Gym')->number('F2', '30');

        self::assertSame(['FORMULA_REFERENCE@Janvier!B2', 'UNMAPPED_LABEL@Janvier!E2'], self::issues($this->plan()));
    }

    public function testTheSummaryCountsRecordsPerMonthAndKind(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100')->text('E2', 'Food')->formula('F2', '1+2', '3');

        $counts = $this->plan()->countsByMonth();

        self::assertSame(['INCOME' => 1, 'EXPENSE' => 2], $counts[1]);
    }
}
