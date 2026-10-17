<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Tests\Module\Import\Support\PlannerTestCase;

final class ImportPlannerAccountsTest extends PlannerTestCase
{
    public function testANegativeSavingsLineIsATransferBackToTheMainAccount(): void
    {
        $this->workbook->monthSheet('Janvier')->text('G2', 'Savings A')->number('H2', '-50');

        self::assertSame(
            ['TRANSFER 2025-01-31 50.00 acc-Savings A>acc-Main current account'],
            self::describe($this->plan()),
        );
    }

    public function testATransferIntoAPortfolioAccountIsFollowedByItsLinkedDeposit(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('G2', 'PEA')->number('H2', '300')
            ->text('G3', 'Savings A')->number('H3', '200');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'TRANSFER 2025-01-31 300.00 acc-Main current account>acc-PEA account',
            'TRANSFER 2025-01-31 200.00 acc-Main current account>acc-Savings A',
            'DEPOSIT 2025-01-31 300.00 acc-PEA account linked',
        ], self::describe($plan));
        $deposit = $plan->steps[2];
        self::assertInstanceOf(PlannedDeposit::class, $deposit);
        self::assertSame('wb:2025:01:SAV:pea:1', $deposit->linkedTransferKey);
    }

    public function testAReverseTransferFromAPortfolioAccountIsNotSupported(): void
    {
        $this->workbook->monthSheet('Janvier')->text('G2', 'PEA')->number('H2', '-50');

        self::assertSame(['FORMULA_NOT_SUPPORTED@Janvier!H2'], self::issues($this->plan()));
    }

    public function testASavingsLabelWithoutATargetIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')->text('G2', 'Unknown target')->number('H2', '10');

        self::assertSame(['UNMAPPED_LABEL@Janvier!G2'], self::issues($this->plan()));
    }

    public function testASavingsTargetThatIsNotAnAccountOrIsTheMainAccountIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('G2', 'Odd')->number('H2', '10')
            ->text('G3', 'Self')->number('H3', '10');
        $mapping = str_replace("savings:\n", "savings:\n  \"Odd\": \"Ghost\"\n  \"Self\": \"Main current account\"\n", self::MAPPING);

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!G2', 'UNMAPPED_ACCOUNT@Janvier!G3'], self::issues($this->plan($mapping)));
    }

    public function testEveryMappedNonPortfolioAccountGetsABalanceSnapshotAndPortfolioAccountsAreSkipped(): void
    {
        $this->references->withAccount('Savings A', 'SNAPSHOTS');
        $this->workbook->monthSheet('Janvier')
            ->text('J2', 'Main account')->number('L2', '100')
            ->text('J3', 'Meals')->number('L3', '20.5')
            ->text('J4', 'PEA')->number('K4', '500')->number('L4', '510')
            ->text('J5', 'Savings A')->number('L5', '300');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'BALANCE 2025-01-31 acc-Main current account 100.00',
            'BALANCE 2025-01-31 acc-Meal vouchers 20.50',
            'BALANCE 2025-01-31 acc-Savings A 300.00',
        ], self::describe($plan));
    }

    public function testAPortfolioAccountRowNeverYieldsAFindingFromItsBalanceCells(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('J4', 'PEA')->number('K4', '1234.567890')->formula('L4', 'PEA!E16+PEA!B16', '1234.567891')
            ->text('J5', 'CTO')->text('L5', 'n/a');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([], $plan->steps);
        self::assertSame([], $plan->warnings);
    }

    public function testASnapshotsBalanceBeyondThePrecisionIsRoundedHalfEvenWithAWarning(): void
    {
        $this->references->withAccount('Main current account', 'SNAPSHOTS')->withAccount('Savings A', 'SNAPSHOTS')->withAccount('Meal vouchers', 'SNAPSHOTS');
        $this->workbook->monthSheet('Janvier')
            ->text('J2', 'Main account')->number('L2', '1234.567890')
            ->text('J3', 'Meals')->number('L3', '10.005')
            ->text('J4', 'Savings A')->number('L4', '10.015')->number('K4', '10.0149');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'BALANCE 2025-01-31 acc-Main current account 1234.57',
            'BALANCE 2025-01-31 acc-Meal vouchers 10.00',
            'BALANCE 2025-01-31 acc-Savings A 10.02',
        ], self::describe($plan));
        self::assertSame(
            ['VALUE_ROUNDED@Janvier!L2', 'VALUE_ROUNDED@Janvier!L3', 'VALUE_ROUNDED@Janvier!K4', 'VALUE_ROUNDED@Janvier!L4'],
            array_map(static fn ($warning): string => sprintf('%s@%s!%s', $warning->code, $warning->sheet, $warning->cell), $plan->warnings),
        );
    }

    public function testATransactionsAccountStaysStrictAboutTheBalancePrecision(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('K2', '10.005')->number('L2', '10.015')->text('J3', 'Meals')->text('L3', 'n/a');

        self::assertSame(
            ['PRECISION_EXCEEDED@Janvier!K2', 'PRECISION_EXCEEDED@Janvier!L2', 'FORMULA_NOT_SUPPORTED@Janvier!L3'],
            self::issues($this->plan()),
        );
    }

    public function testABalanceProblemOfAnIgnoredRowIsNeverRaised(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J4', 'Mystery')->number('L4', '10.005');

        $plan = $this->plan(self::MAPPING."\nignore:\n  \"Mystery\": \"Not an account\"\n");

        self::assertSame([], $plan->issues);
    }

    public function testASnapshotCarriesItsSourceReferenceInItsComment(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('L2', '100');

        $step = $this->plan()->steps[0];

        self::assertInstanceOf(PlannedBalance::class, $step);
        self::assertSame('Imported from workbook 2025, Janvier L2 (wb:2025:01:ACC:main-account:1)', $step->comment);
    }

    public function testAnEmptyClosingBalanceIsNeverZero(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('K2', '0');

        self::assertSame([], $this->plan()->steps);
    }

    public function testAnAccountRowThatIsNotInTheMappingIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('L2', '1')->text('J4', 'Mystery')->number('L4', '1');

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!J4'], self::issues($this->plan()));
    }

    public function testAnIgnoredAccountRowIsSkipped(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J4', 'Mystery')->number('L4', '1');

        $plan = $this->plan(self::MAPPING."\nignore:\n  \"Mystery\": \"Not an account\"\n");

        self::assertSame([], $plan->issues);
        self::assertSame(1, $plan->ignoredLines);
    }

    public function testTwoRowsOfOneMonthForTheSameCadranAccountAreAmbiguous(): void
    {
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('L2', '1')->text('J3', 'Main alias')->number('L3', '2');
        $mapping = str_replace("accounts:\n", "accounts:\n  \"Main alias\": \"Main current account\"\n", self::MAPPING);

        self::assertSame(['UNMAPPED_ACCOUNT@Janvier!J3'], self::issues($this->plan($mapping)));
    }

    public function testASnapshotBeforeTheAccountOpeningIsRejected(): void
    {
        $this->references->withAccount('Main current account', openedOn: '2025-02-01');
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('L2', '1');

        self::assertSame(['ACCOUNT_OPENED_AFTER_DATE@Janvier!L2'], self::issues($this->plan()));
    }

    public function testAnOpeningAdjustmentIsPlannedOnlyForTheFirstMonthAndOnlyWithoutEarlierHistory(): void
    {
        $this->workbook->monthSheet('Février')->text('J2', 'Main account')->number('K2', '999');
        $this->workbook->monthSheet('Janvier')
            ->text('J2', 'Main account')->number('K2', '-250')
            ->text('J3', 'Meals')->number('K3', '40');
        $this->references->accountsWithHistory['acc-Meal vouchers'] = true;

        $plan = $this->plan();

        self::assertSame(
            ['OPENING_ADJUSTMENT 2024-12-31 -250.00 wb:2025:01:OPEN:main-account:1'],
            array_slice(self::describe($plan), 0, 1),
        );
        self::assertCount(1, array_filter(self::describe($plan), static fn (string $line): bool => str_starts_with($line, 'OPENING_ADJUSTMENT')));
        self::assertSame('Opening balance (workbook import)', self::transactionAt($plan, 0)->rawLabel);
        self::assertSame('Imported from workbook 2025, Janvier K2', self::transactionAt($plan, 0)->note);
    }

    public function testAnOpeningBalanceOfZeroOrOnASnapshotAccountPlansNothing(): void
    {
        $this->references->withAccount('Savings A', 'SNAPSHOTS');
        $this->workbook->monthSheet('Janvier')
            ->text('J2', 'Main account')->number('K2', '0')
            ->text('J3', 'Savings A')->number('K3', '700');

        self::assertSame([], $this->plan()->steps);
    }

    public function testTheOpeningRecordsOfNovemberAreDatedOnTheLastDayOfOctober(): void
    {
        $this->workbook->monthSheet('Novembre')->text('J2', 'Main account')->number('K2', '12.34');

        self::assertSame(
            ['OPENING_ADJUSTMENT 2023-10-31 12.34 wb:2023:11:OPEN:main-account:1'],
            self::describe($this->plan(year: 2023)),
        );
    }

    public function testAnAccountOpenedAfterTheOpeningDateRejectsTheOpeningAdjustment(): void
    {
        $this->references->withAccount('Main current account', openedOn: '2025-01-01');
        $this->workbook->monthSheet('Janvier')->text('J2', 'Main account')->number('K2', '10');

        self::assertSame(['ACCOUNT_OPENED_AFTER_DATE@Janvier!K2'], self::issues($this->plan()));
    }
}
