<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Domain\Mapping\InstrumentKind;
use App\Module\Import\Domain\Plan\PlannedBuy;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedValuation;
use App\Tests\Module\Import\Support\PlannerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImportPlannerPortfolioTest extends PlannerTestCase
{
    private const string UNIT_NOTE = 'Quantity unknown in the workbook: amount imported as quantity at unit price 1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->references->withInstrument('World ETF')->withInstrument('Tech ETF')->withInstrument('Bond Fund');
    }

    public function testAProductTermGivesTheWholeFactorAsQuantityWhicheverSideItIsOn(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')
            ->formula('D4', '3*95.12+95.12*4', '665.84');

        $plan = $this->plan();

        // 3 x 95.12 = 285.36 and 4 x 95.12 = 380.48, which add up to 665.84.
        self::assertSame([], self::issues($plan));
        self::assertSame([
            'BUY 2025-01-31 3 x 95.12 = 285.36 World ETF [wb:2025:01:PEA:D:1]',
            'BUY 2025-01-31 4 x 95.12 = 380.48 World ETF [wb:2025:01:PEA:D:2]',
        ], self::describe($plan));
    }

    public function testAPlainNumberIsBoughtAsQuantityAtUnitPriceOne(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('D4', '100');

        $plan = $this->plan();

        self::assertSame(['BUY 2025-01-31 100.00 x 1 = 100.00 World ETF [wb:2025:01:PEA:D:1]'], self::describe($plan));
        $buy = $plan->steps[0];
        self::assertInstanceOf(PlannedBuy::class, $buy);
        self::assertSame(self::UNIT_NOTE, $buy->note);
        self::assertSame('acc-PEA account', $buy->accountId);
    }

    public function testBothOrNeitherFactorWholeIsAmbiguousUnlessTheMappingOverridesIt(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '2*3', '6');

        self::assertSame(['AMBIGUOUS_QUANTITY@PEA!D4'], self::issues($this->plan()));

        $quantityFirst = $this->plan(self::MAPPING."\noverrides:\n  \"2025-01\": { pea_quantity_first: [true] }\n");
        $priceFirst = $this->plan(self::MAPPING."\noverrides:\n  \"2025-01\": { pea_quantity_first: [false] }\n");

        self::assertSame(['BUY 2025-01-31 2 x 3 = 6.00 World ETF [wb:2025:01:PEA:D:1]'], self::describe($quantityFirst));
        self::assertSame(['BUY 2025-01-31 3 x 2 = 6.00 World ETF [wb:2025:01:PEA:D:1]'], self::describe($priceFirst));
    }

    public function testAUnitPriceBeyondTheInstrumentPrecisionExceedsIt(): void
    {
        $this->workbook->monthSheet('Janvier');
        // 0.00001 has five decimals and the price precision is four: 10 000 000 x 0.00001 = 100.
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '0.00001*10000000', '100');

        self::assertSame(['PRECISION_EXCEEDED@PEA!D4'], self::issues($this->plan()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function productsBeyondCashPrecision(): iterable
    {
        yield 'twelve at 25.1234' => ['12*25.1234', '301.4808', '12 x 25.1234 = 301.48'];
        yield 'half-even down on the 0.0015 tie' => ['3*10.0005', '30.0015', '3 x 10.0005 = 30.00'];
        yield 'rounds up above the tie' => ['7*1.2345', '8.6415', '7 x 1.2345 = 8.64'];
    }

    #[DataProvider('productsBeyondCashPrecision')]
    public function testAPeaProductBeyondCashPrecisionKeepsQuantityAndPriceExactAndRoundsOnlyTheGross(string $formula, string $cached, string $expected): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', $formula, $cached);

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame(['BUY 2025-01-31 '.$expected.' World ETF [wb:2025:01:PEA:D:1]'], self::describe($plan));
    }

    public function testACtoProductBeyondCashPrecisionIsBoughtAtItsRoundedGross(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('A4', 'Janvier')->formula('D4', '12*25.1234', '301.4808');

        self::assertSame(['BUY 2025-01-31 301.48 x 1 = 301.48 Tech ETF [wb:2025:01:CTO:D:1]'], self::describe($this->plan()));
    }

    public function testAProductWhosePriceExceedsTheInstrumentPrecisionStaysRejected(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '5*0.00001', '0.00005');

        self::assertSame(['PRECISION_EXCEEDED@PEA!D4'], self::issues($this->plan()));
    }

    public function testASumOrPlainNumberBeyondCashPrecisionStaysRejected(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '10.123+1', '11.123')->number('D5', '12.345')->text('A5', 'Février');
        $this->workbook->monthSheet('Février');

        self::assertSame(['PRECISION_EXCEEDED@PEA!D4', 'PRECISION_EXCEEDED@PEA!D5'], self::issues($this->plan()));
    }

    public function testTheCachedValueOfAProductBeyondCashPrecisionIsStillCheckedAgainstTheExactSum(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '12*25.1234', '301.5');

        self::assertSame(['CACHED_VALUE_MISMATCH@PEA!D4'], self::issues($this->plan()));
    }

    public function testANegativePurchaseIsRejectedNeverImportedAsANegativeBuy(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('A4', 'Janvier')->formula('D4', '100-30', '70');

        self::assertSame(['FORMULA_NOT_SUPPORTED@CTO_TR!D4'], self::issues($this->plan()));
    }

    public function testAValuationIsTheSecuritiesValueAndTheCash(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('B4', '20.25')->number('E4', '1000');

        self::assertSame(
            ['VALUATION 2025-01-31 total 1020.25 cash 20.25 acc-PEA account'],
            self::describe($this->plan()),
        );
    }

    public function testASecuritiesValueAndCashBeyondTheCashPrecisionAreRoundedHalfEvenWithWarnings(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('B4', '20.005')->number('E4', '1234.567890');
        $this->workbook->monthSheet('Février');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('A4', 'Janvier')->text('A5', 'Février')->number('B5', '10.015')->number('H5', '5.5');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([
            'VALUATION 2025-01-31 total 1254.57 cash 20.00 acc-PEA account',
            'VALUATION 2025-02-28 total 15.52 cash 10.02 acc-Broker account',
        ], self::describe($plan));
        self::assertSame(
            ['VALUE_ROUNDED@PEA!B4', 'VALUE_ROUNDED@PEA!E4', 'VALUE_ROUNDED@CTO_TR!B5'],
            array_map(static fn ($warning): string => sprintf('%s@%s!%s', $warning->code, $warning->sheet, $warning->cell), $plan->warnings),
        );
    }

    public function testADepositBeyondTheCashPrecisionStaysRejected(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('C4', '10.005');

        self::assertSame(['PRECISION_EXCEEDED@PEA!C4'], self::issues($this->plan()));
    }

    public function testAValuationNoteCarriesItsSourceReferenceAndTheOpeningOneIsTaggedOpen(): void
    {
        $this->workbook->monthSheet('Novembre');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A4', 'Janvier')
            ->text('A13', 'Octobre')->number('B13', '20')->number('E13', '900')
            ->text('A14', 'Novembre')->number('B14', '25')->number('E14', '950');

        $steps = $this->plan(year: 2023)->steps;

        self::assertInstanceOf(PlannedValuation::class, $steps[0]);
        self::assertInstanceOf(PlannedValuation::class, $steps[1]);
        self::assertSame('Imported from workbook 2023, PEA E13 (wb:2023:11:OPEN:pea-e:1)', $steps[0]->note);
        self::assertSame('Imported from workbook 2023, PEA E14 (wb:2023:11:PEA:E:1)', $steps[1]->note);
    }

    public function testNoSecuritiesValueMeansNoValuationAndNoCashMeansAnExclusion(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->monthSheet('Février');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A4', 'Janvier')->number('B4', '20')
            ->text('A5', 'Février')->number('E5', '1000');

        $plan = $this->plan();

        self::assertSame(['LABEL_WITHOUT_AMOUNT@PEA!B5'], self::issues($plan));
        self::assertFalse($plan->hasRejections());
        self::assertSame([], $plan->steps);
    }

    public function testACtoSheetBuysPerInstrumentColumnAndValuesTheWholeSheet(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('E2', 'Bond Fund')
            ->text('A4', 'Janvier')->number('B4', '100')->number('D4', '100')->formula('E4', '50+50', '100')->number('H4', '2000');

        self::assertSame([
            'BUY 2025-01-31 100.00 x 1 = 100.00 Tech ETF [wb:2025:01:CTO:D:1]',
            'BUY 2025-01-31 50.00 x 1 = 50.00 Bond Fund [wb:2025:01:CTO:E:1]',
            'BUY 2025-01-31 50.00 x 1 = 50.00 Bond Fund [wb:2025:01:CTO:E:2]',
            'VALUATION 2025-01-31 total 2100.00 cash 100.00 acc-Broker account',
        ], self::describe($this->plan()));
    }

    public function testAnInstrumentMissingFromTheWorkspaceIsPlannedForCreationOnce(): void
    {
        $this->references = (new \App\Tests\Module\Import\Support\FakeImportReferences())
            ->withAccount('Main current account')->withAccount('PEA account', 'PORTFOLIO');
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->formula('D4', '10+20', '30');

        $plan = $this->plan();

        self::assertCount(1, $plan->instrumentsToCreate);
        self::assertSame('World ETF', $plan->instrumentsToCreate[0]->name);
        self::assertSame(InstrumentKind::ETF, $plan->instrumentsToCreate[0]->type);
    }

    public function testAnInstrumentToCreateNeedsAnIsinOrATicker(): void
    {
        $this->references = (new \App\Tests\Module\Import\Support\FakeImportReferences())
            ->withAccount('Main current account')->withAccount('PEA account', 'PORTFOLIO');
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('D4', '100');
        $anonymous = str_replace('{ name: "World ETF", type: ETF, ticker: "WRLD" }', '{ name: "World ETF", type: ETF }', self::MAPPING);

        self::assertSame(['UNMAPPED_LABEL@PEA!D2'], self::issues($this->plan($anonymous)));
    }

    public function testAPortfolioColumnWithoutAnInstrumentMappingIsUnmapped(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Mystery')->text('A4', 'Janvier')->number('D4', '10');

        self::assertSame(['UNMAPPED_LABEL@CTO_TR!D2'], self::issues($this->plan()));
    }

    public function testAPortfolioSheetWithoutAccountMappingIsUnmappedAndNeedsAPortfolioAccount(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('A4', 'Janvier')->number('D4', '10');
        $withoutPortfolio = substr(self::MAPPING, 0, (int) strpos(self::MAPPING, 'portfolio:'))."portfolio:\n  pea: null\n  cto_tr: null\n";

        self::assertSame(['UNMAPPED_ACCOUNT@CTO_TR!-'], self::issues($this->plan($withoutPortfolio)));

        $this->references->withAccount('Broker account', 'TRANSACTIONS');

        self::assertSame(['UNMAPPED_ACCOUNT@CTO_TR!-'], self::issues($this->plan()));
    }

    public function testASheetWithNothingToImportThisYearNeedsNoMapping(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF');
        $withoutPortfolio = substr(self::MAPPING, 0, (int) strpos(self::MAPPING, 'portfolio:'))."portfolio:\n  pea: null\n  cto_tr: null\n";

        self::assertSame([], self::issues($this->plan($withoutPortfolio)));
    }

    public function testTheMonthsBeforeTheFirstSheetFormTheOpeningStateOfThePortfolio(): void
    {
        $this->workbook->monthSheet('Novembre');
        $this->workbook->monthSheet('Décembre');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '10')->number('C3', '500')->number('D3', '40')->number('E3', '510')
            ->text('A4', 'Janvier')
            ->text('A13', 'Octobre')->number('B13', '20')->number('C13', '300')->number('D13', '100')->number('E13', '900')
            ->text('A14', 'Novembre')->number('B14', '25')->number('C14', '50')->number('E14', '950')
            ->text('A15', 'Décembre')->number('B15', '30')->number('E15', '1000');

        $plan = $this->plan(year: 2023);

        // Deposits 500 (row 3) + 300 (October) = 800; the November deposit comes from the savings transfer, not from here.
        self::assertSame([
            'DEPOSIT 2023-10-31 800.00 acc-PEA account unlinked',
            'BUY 2023-10-31 40.00 x 1 = 40.00 World ETF [wb:2023:11:OPEN:pea-d:1]',
            'BUY 2023-10-31 100.00 x 1 = 100.00 World ETF [wb:2023:11:OPEN:pea-d:2]',
            'VALUATION 2023-10-31 total 920.00 cash 20.00 acc-PEA account',
            'VALUATION 2023-11-30 total 975.00 cash 25.00 acc-PEA account',
            'VALUATION 2023-12-31 total 1030.00 cash 30.00 acc-PEA account',
        ], self::describe($plan));
    }

    public function testAReadingProblemOfTheOpeningRowIsIgnoredWhenTheOpeningStateIsNotUsed(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '10')->number('D3', '25.12345678901234')->number('E3', '510.123')
            ->text('A4', 'Janvier')->number('B4', '20')->number('E4', '1000');

        $plan = $this->plan();

        self::assertSame([], $plan->issues);
        self::assertSame([], $plan->warnings);
        self::assertSame(['VALUATION 2025-01-31 total 1020.00 cash 20.00 acc-PEA account'], self::describe($plan));
    }

    public function testTheSameOpeningRowIsRejectedWhenTheOpeningStateIsUsed(): void
    {
        $this->workbook->monthSheet('Novembre');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '10')->number('D3', '25.12345678901234')
            ->text('A4', 'Janvier')
            ->text('A13', 'Octobre')->number('B13', '20')->number('E13', '900');

        self::assertSame(['PRECISION_EXCEEDED@PEA!D3'], self::issues($this->plan(year: 2023)));
    }

    public function testTheOpeningReferencesOfPeaAndCtoAreDistinct(): void
    {
        $this->workbook->monthSheet('Novembre');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A4', 'Janvier')
            ->text('A13', 'Octobre')->number('B13', '1')->number('C13', '100')->number('D13', '10')->number('E13', '20');
        $this->workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')
            ->text('A4', 'Janvier')
            ->text('A13', 'Octobre')->number('B13', '2')->number('C13', '200')->number('D13', '30')->number('H13', '40');

        $plan = $this->plan(year: 2023);

        $references = array_map(static fn ($step): ?string => $step instanceof PlannedBuy ? $step->reference : null, $plan->steps);
        self::assertSame(['wb:2023:11:OPEN:pea-d:1', 'wb:2023:11:OPEN:cto-tr-d:1'], array_values(array_filter($references)));
        $deposits = array_map(static fn ($step): ?string => $step instanceof PlannedDeposit ? $step->reference : null, $plan->steps);
        self::assertSame(['wb:2023:11:OPEN:pea-c:1', 'wb:2023:11:OPEN:cto-tr-c:1'], array_values(array_filter($deposits)));
        self::assertCount(2, array_filter(self::describe($plan), static fn (string $line): bool => str_starts_with($line, 'VALUATION 2023-10-31')));
    }

    public function testThePreviousDecemberAloneIsNotAnOpeningStateOnceTheYearBeforeWasImported(): void
    {
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '10')->number('C3', '500')->number('E3', '510');

        self::assertSame([], self::describe($this->plan()));
    }

    public function testTheMonthOrderPutsDepositsBuysBalancesAndValuationsAfterTheTransfers(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('G2', 'PEA')->number('H2', '300')
            ->text('J2', 'Main account')->number('L2', '100');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('B4', '5')->number('D4', '300')->number('E4', '295');

        self::assertSame([
            'TRANSFER 2025-01-31 300.00 acc-Main current account>acc-PEA account',
            'DEPOSIT 2025-01-31 300.00 acc-PEA account linked',
            'BUY 2025-01-31 300.00 x 1 = 300.00 World ETF [wb:2025:01:PEA:D:1]',
            'BALANCE 2025-01-31 acc-Main current account 100.00',
            'VALUATION 2025-01-31 total 300.00 cash 5.00 acc-PEA account',
        ], self::describe($this->plan()));
    }

    public function testABuyBeforeThePortfolioAccountOpeningIsRejected(): void
    {
        $this->references->withAccount('PEA account', 'PORTFOLIO', '2025-02-01');
        $this->workbook->monthSheet('Janvier');
        $this->workbook->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Janvier')->number('D4', '100');

        self::assertSame(['ACCOUNT_OPENED_AFTER_DATE@PEA!D4'], self::issues($this->plan()));
    }
}
