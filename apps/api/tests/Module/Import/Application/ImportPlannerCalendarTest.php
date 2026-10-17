<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Application\Planning\PlanningState;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;
use App\Module\Import\Infrastructure\Mapping\YamlMappingReader;
use App\Tests\Module\Import\Support\FakeImportReferences;
use App\Tests\Module\Import\Support\PlannerTestCase;

/** Months against the clock: the current month is dated today, a month that has not started is left out. */
final class ImportPlannerCalendarTest extends PlannerTestCase
{
    public function testEveryRecordOfTheCurrentMonthIsDatedTodayAndTheOpeningKeepsTheEndOfThePreviousMonth(): void
    {
        $this->references->today = '2025-01-15';
        $this->workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->number('B2', '2500')
            ->text('C2', 'Rent')->number('D2', '800')
            ->text('E2', 'Food')->formula('F2', '20-5', '15')
            ->text('E3', 'Food')->number('F3', '30')
            ->text('G2', 'PEA')->number('H2', '300')
            ->text('J2', 'Main account')->number('K2', '40')->number('L2', '1000');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('B4', '5')->number('D4', '100')->number('E4', '295');
        $this->references->withInstrument('World ETF');

        $plan = $this->plan();

        self::assertSame([], self::issues($plan));
        self::assertSame([
            'OPENING_ADJUSTMENT 2024-12-31 40.00 wb:2025:01:OPEN:main-account:1',
            'INCOME 2025-01-15 2500.00 wb:2025:01:INC:salary:1',
            'EXPENSE 2025-01-15 -800.00 wb:2025:01:FIX:rent:1',
            'EXPENSE 2025-01-15 -20.00 wb:2025:01:VAR:food:1',
            'EXPENSE 2025-01-15 -30.00 wb:2025:01:VAR:food:3',
            'REFUND 2025-01-15 5.00 of wb:2025:01:VAR:food:1',
            'TRANSFER 2025-01-15 300.00 acc-Main current account>acc-PEA account',
            'DEPOSIT 2025-01-15 300.00 acc-PEA account linked',
            'BUY 2025-01-15 100.00 x 1 = 100.00 World ETF [wb:2025:01:PEA:D:1]',
            'BALANCE 2025-01-15 acc-Main current account 1000.00',
            'VALUATION 2025-01-15 total 300.00 cash 5.00 acc-PEA account',
        ], self::describe($plan));
        self::assertSame('Imported from workbook 2025, Janvier B2', self::transactionAt($plan, 1)->note);
    }

    public function testTodayBeingTheLastDayOfTheMonthDatesNormally(): void
    {
        $this->references->today = '2025-01-31';
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');

        self::assertSame(['INCOME 2025-01-31 100.00 wb:2025:01:INC:salary:1'], self::describe($this->plan()));
    }

    public function testAMonthThatHasNotStartedIsExcludedWholeWithItsPortfolioRows(): void
    {
        $this->references->today = '2025-01-15';
        $this->references->withInstrument('World ETF');
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');
        $this->workbook->monthSheet('Février')->text('A2', 'Salary')->number('B2', '200')->text('J2', 'Main account')->number('K2', '9')->number('L2', '9');
        $this->workbook->monthSheet('Mars')->text('C2', 'Rent')->number('D2', '300');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A4', 'Janvier')->number('B4', '5')->number('E4', '50')
            ->text('A5', 'Février')->number('B5', '6')->number('D5', '60')->number('E5', '70');

        $plan = $this->plan();

        self::assertSame(['MONTH_NOT_ENDED@Février!-', 'MONTH_NOT_ENDED@Mars!-'], self::issues($plan));
        self::assertFalse($plan->hasRejections(), 'an unfinished month is excluded, not rejected');
        self::assertSame([
            'INCOME 2025-01-15 100.00 wb:2025:01:INC:salary:1',
            'VALUATION 2025-01-15 total 55.00 cash 5.00 acc-PEA account',
        ], self::describe($plan));
    }

    public function testAnExcludedMonthCannotRejectTheYearWhateverItHolds(): void
    {
        $this->references->today = '2025-01-15';
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '100');
        $this->workbook->monthSheet('Février')->text('A2', 'Salary')->formula('B2', 'B3+1', '5')->text('E2', 'Unknown')->number('F2', '1');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->text('A5', 'Février')->formula('D5', 'B3+1', '5');

        $plan = $this->plan();

        self::assertSame(['MONTH_NOT_ENDED@Février!-'], self::issues($plan));
        self::assertFalse($plan->hasRejections());
    }

    public function testAYearWhoseEverySheetIsInTheFutureHasNoOpeningAndNothingToWrite(): void
    {
        $this->references->today = '2024-12-15';
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('K2', '40')->text('A2', 'Salary')->number('B2', '100');

        $plan = $this->plan();

        self::assertSame(['MONTH_NOT_ENDED@Janvier!-'], self::issues($plan));
        self::assertSame([], $plan->steps);
    }

    public function testABookingStillAfterTodayIsARejectionOfItsOwnAndNeverAccountOpening(): void
    {
        $references = (new FakeImportReferences())->withAccount('Main current account');
        $references->today = '2025-01-15';
        $workbook = new ParsedWorkbook(2025, [], null, null, [], [], []);
        $state = new PlanningState($references, (new YamlMappingReader())->fromYaml(self::MAPPING), 2025, $workbook->issues);

        $state->emit(new PlannedBalance(1, 'Janvier', 'L2', 'acc-Main current account', DecimalValue::fromString('1.00'), '2025-01-16', 'note'), '2025-01-16');

        self::assertSame(['MONTH_NOT_ENDED'], array_map(static fn ($issue): string => $issue->code->value, $state->issues()));
        self::assertTrue($state->issues()[0]->isRejection());
    }
}
