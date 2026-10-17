<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Infrastructure;

use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\ParsedMonth;
use App\Module\Import\Domain\Workbook\ParsedMovement;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;
use App\Module\Import\Domain\Workbook\WorkbookLayoutReader;
use App\Module\Import\Infrastructure\Xlsx\XlsxPackage;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Reads synthetic workbooks (neutral labels only) through the real archive
 * reader, so the layout rules are checked against what a spreadsheet file
 * actually stores.
 */
final class WorkbookLayoutReaderTest extends TestCase
{
    private WorkbookBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WorkbookBuilder();
    }

    protected function tearDown(): void
    {
        $this->builder->cleanUp();
    }

    private function read(int $year = 2025): ParsedWorkbook
    {
        $package = XlsxPackage::open($this->builder->save('2025 sample.xlsx'));

        return (new WorkbookLayoutReader())->read($package, $year, 2);
    }

    private function month(ParsedWorkbook $workbook, int $month): ParsedMonth
    {
        $parsed = $workbook->month($month);
        self::assertNotNull($parsed, 'month sheet expected');

        return $parsed;
    }

    /**
     * @return list<string>
     */
    private function issueSummary(ParsedWorkbook $workbook): array
    {
        return array_map(static fn ($issue): string => sprintf('%s@%s!%s', $issue->code->value, $issue->sheet ?? '-', $issue->cell ?? '-'), $workbook->issues);
    }

    public function testMonthSheetsAreFoundByNormalizedNameInAnyOrderAndOtherSheetsAreIgnored(): void
    {
        $this->builder->monthSheet('Février');
        $this->builder->sheet('Recap Annuel');
        $this->builder->monthSheet('JANVIER');
        $this->builder->monthSheet('Août');
        $this->builder->sheet('Impôts');

        $workbook = $this->read();

        self::assertSame([1, 2, 8], array_map(static fn (ParsedMonth $month): int => $month->month, $workbook->months));
        self::assertSame(['Recap Annuel', 'Impôts'], $workbook->ignoredSheets);
        self::assertSame(2025, $workbook->year);
        self::assertSame([], $workbook->issues);
    }

    public function testAWorkbookWithoutAnyMonthSheetIsAnUnknownLayout(): void
    {
        $this->builder->sheet('Recap');

        self::assertSame(['UNKNOWN_LAYOUT@-!-'], $this->issueSummary($this->read()));
    }

    public function testTwoSheetsWithTheSameNormalizedNameAreAmbiguous(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->monthSheet('janvier ');

        $workbook = $this->read();

        self::assertSame(['UNKNOWN_SHEET_NAME@janvier !-'], $this->issueSummary($workbook));
        self::assertCount(1, $workbook->months);
    }

    public function testAHeaderMismatchIsAnUnknownLayoutAndTheSheetIsNotRead(): void
    {
        $this->builder->sheet('Janvier')
            ->text('A1', 'Revenus')->text('C1', 'Dépenses Fixes')->text('E1', 'Autre chose')
            ->text('G1', 'Épargnes')->text('J1', 'Comptes')->text('K1', 'Solde N-1')->text('L1', 'Solde N')
            ->text('A2', 'Salary')->number('B2', '100');

        $workbook = $this->read();

        self::assertSame(['UNKNOWN_LAYOUT@Janvier!E1'], $this->issueSummary($workbook));
        self::assertSame([], $workbook->months);
    }

    public function testHeadersAreComparedAfterNormalization(): void
    {
        $this->builder->sheet('Janvier')
            ->text('A1', 'REVENUS (hors aides)')->text('C1', 'dépenses fixes :')->text('E1', 'Dépenses  Variables')
            ->text('G1', 'Épargnes')->text('J1', 'Comptes')->text('K1', 'Solde N-1')->text('L1', 'Solde N');

        self::assertSame([], $this->read()->issues);
    }

    public function testEachTopLevelTermIsOneMovementWithAnOrdinalPerLabelAndMonth(): void
    {
        $this->builder->monthSheet('Mars')
            ->text('E2', 'Food')->formula('F2', '12.5+48.2+7.9', '68.6')
            ->text('E3', 'Rent')->number('F3', '700')
            ->text('E4', 'food')->number('F4', '10')
            ->text('A2', 'Salary')->number('B2', '2000.00');

        $month = $this->month($this->read(), 3);
        $food = array_merge(...array_map(static fn ($line): array => $line->movements, array_filter($month->linesOf(Block::VAR), static fn ($line): bool => 'food' === $line->labelKey)));

        self::assertSame(['12.50', '48.20', '7.90', '10.00'], array_map(static fn (ParsedMovement $m): string => $m->amount->toString(), $food));
        self::assertSame([1, 2, 3, 4], array_map(static fn (ParsedMovement $m): int => $m->ordinal, $food));
        self::assertSame('wb:2025:03:VAR:food:4', $food[3]->referenceIn(2025)->toString());
        self::assertSame('F2', $food[0]->cell);
        self::assertSame('Mars', $food[0]->sheet);
        self::assertSame('Food', $food[0]->rawLabel);
        self::assertSame('wb:2025:03:INC:salary:1', $month->linesOf(Block::INC)[0]->movements[0]->referenceIn(2025)->toString());
    }

    public function testEveryBlockMapsToItsColumnPairAndRecordKind(): void
    {
        $this->builder->monthSheet('Mars')
            ->text('A2', 'Salary')->number('B2', '1')
            ->text('C2', 'Rent')->number('D2', '2')
            ->text('E2', 'Food')->number('F2', '3')
            ->text('G2', 'Savings A')->number('H2', '4');

        $month = $this->month($this->read(), 3);

        foreach ([[Block::INC, 'B2'], [Block::FIX, 'D2'], [Block::VAR, 'F2'], [Block::SAV, 'H2']] as [$block, $cell]) {
            $lines = $month->linesOf($block);
            self::assertCount(1, $lines);
            self::assertSame($cell, $lines[0]->amountCell);
            self::assertSame($block, $lines[0]->movements[0]->block);
        }
    }

    public function testRowsBeyondTheUsualEndAreStillRead(): void
    {
        $this->builder->monthSheet('Mars')->text('E40', 'Food')->number('F40', '9');

        self::assertCount(1, $this->month($this->read(), 3)->linesOf(Block::VAR));
    }

    public function testRowHandlingSkipsExcludesAndRejects(): void
    {
        $this->builder->monthSheet('Mars')
            ->text('A2', '   ')                                  // blank label, no amount: skipped silently
            ->text('A3', 'Salary')                               // label without amount: excluded
            ->number('B4', '50')                                 // amount without label: rejected
            ->text('A5', 'Bonus')->number('B5', '0')             // zero: excluded
            ->number('B6', '0')                                  // zero without label: excluded, not rejected
            ->text('A7', 'Gift')->formula('B7', 'B3', '5');      // reference: rejected

        $workbook = $this->read();

        self::assertSame(
            [
                'LABEL_WITHOUT_AMOUNT@Mars!A3',
                'AMOUNT_WITHOUT_LABEL@Mars!B4',
                'ZERO_AMOUNT@Mars!B5',
                'ZERO_AMOUNT@Mars!B6',
                'FORMULA_REFERENCE@Mars!B7',
            ],
            $this->issueSummary($workbook),
        );
        self::assertCount(2, $workbook->rejections());
        self::assertCount(3, $workbook->exclusions());
        self::assertTrue($workbook->hasRejections());

        $lines = $this->month($workbook, 3)->linesOf(Block::INC);
        self::assertSame(['salary', 'bonus', 'gift'], array_map(static fn ($line): string => $line->labelKey, array_filter($lines, static fn ($line): bool => '' !== $line->labelKey)));
        self::assertSame(ReasonCode::FORMULA_REFERENCE, array_reverse($lines)[0]->rejection);
    }

    public function testAZeroTermInsideAFormulaIsReportedAndDropsOnlyThatTerm(): void
    {
        $this->builder->monthSheet('Mars')->text('E2', 'Food')->formula('F2', '0+3', '3');

        $workbook = $this->read();

        self::assertSame(['ZERO_AMOUNT@Mars!F2'], $this->issueSummary($workbook));
        self::assertCount(1, $this->month($workbook, 3)->linesOf(Block::VAR)[0]->movements);
    }

    public function testACachedValueMismatchIsLocatedByCell(): void
    {
        $this->builder->monthSheet('Mars')->text('E2', 'Food')->formula('F2', '12.5+48.2+7.9', '68.7');

        self::assertSame(['CACHED_VALUE_MISMATCH@Mars!F2'], $this->issueSummary($this->read()));
    }

    public function testIncomeCannotBeNegativeButAnExpenseOrSavingsTermMayBe(): void
    {
        $this->builder->monthSheet('Mars')
            ->text('A2', 'Salary')->formula('B2', '100-30', '70')
            ->text('E2', 'Food')->formula('F2', '12.5+48.2-7.9', '52.8')
            ->text('G2', 'Savings A')->number('H2', '-50');

        $workbook = $this->read();
        $month = $this->month($workbook, 3);

        self::assertSame(['NEGATIVE_INCOME@Mars!B2'], $this->issueSummary($workbook));
        self::assertSame(
            ['12.50', '48.20', '-7.90'],
            array_map(static fn (ParsedMovement $m): string => $m->amount->toString(), $month->linesOf(Block::VAR)[0]->movements),
        );
        self::assertSame('-50.00', $month->linesOf(Block::SAV)[0]->movements[0]->amount->toString());
    }

    public function testAFloatArtifactIsNormalizedWithAWarning(): void
    {
        $this->builder->monthSheet('Mars')->text('A2', 'Salary')->number('B2', '90.4899999999998');

        $workbook = $this->read();

        self::assertSame('90.49', $this->month($workbook, 3)->linesOf(Block::INC)[0]->movements[0]->amount->toString());
        self::assertCount(1, $workbook->warnings);
        self::assertSame('B2', $workbook->warnings[0]->cell);
        self::assertSame([], $workbook->issues);
    }

    public function testNumericLabelsAndInlineStringsAreLabels(): void
    {
        $this->builder->monthSheet('Mars')->number('A2', '2024')->number('B2', '5')->inline('A3', 'Café du coin')->number('B3', '3');

        $keys = array_map(static fn ($line): string => $line->labelKey, $this->month($this->read(), 3)->linesOf(Block::INC));

        self::assertSame(['2024', 'cafe-du-coin'], $keys);
    }

    public function testTheAccountTableReadsCachedBalancesAndLeavesEmptyCellsNull(): void
    {
        $this->builder->monthSheet('Mars')
            ->text('J2', 'Savings A')->formula('K2', 'Fevrier!L2', '1000.0000000000001')->formula('L2', 'K2+200', '1200')
            ->text('J3', 'Main')->number('K3', '0')
            ->text('J4', 'Cash')->number('L4', '15.5')
            ->number('K5', '99')                 // no label: the row is skipped
            ->text('J11', 'Beyond')->number('L11', '5');   // below row 10: ignored

        $accounts = $this->month($this->read(), 3)->accounts;

        self::assertCount(3, $accounts);
        self::assertSame(['savings-a', '1000.00', '1200.00'], [$accounts[0]->labelKey, $accounts[0]->opening?->toString(), $accounts[0]->closing?->toString()]);
        self::assertSame('0.00', $accounts[1]->opening?->toString());
        self::assertNull($accounts[1]->closing);
        self::assertNull($accounts[2]->opening);
        self::assertSame('15.50', $accounts[2]->closing?->toString());
    }

    public function testAnErrorInAnAccountBalanceIsRecordedOnTheRowAndLeftToThePlanner(): void
    {
        $this->builder->monthSheet('Mars')->text('J2', 'Savings A')->error('L2', '#REF!')->number('K2', '1.234567');

        $workbook = $this->read();
        $row = $this->month($workbook, 3)->accounts[0];

        self::assertSame([], $this->issueSummary($workbook));
        self::assertSame([ReasonCode::FORMULA_NOT_SUPPORTED, null], [$row->closingProblem, $row->openingProblem]);
        self::assertSame(['1.23', true], [$row->opening?->toString(), $row->openingRounded]);
    }

    public function testThePeaSheetIsMatchedByMonthNameNotByPosition(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')
            ->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '10')->number('C3', '500')->number('E3', '510')
            ->text('A4', 'Janvier')->number('B4', '30')->number('D4', '100')
            ->text('A6', 'Février')->number('B6', '20')->number('E6', '700')->formula('D6', '2*95.12+1*50', '240.24')
            ->text('A16', 'Total')->number('B16', '999');

        $pea = $this->read()->pea;

        self::assertNotNull($pea);
        self::assertSame(Block::PEA, $pea->kind);
        self::assertSame(['D'], array_map(static fn ($i): string => $i->column, $pea->instruments));
        self::assertSame('MSCI World', $pea->instruments[0]->header);
        self::assertSame('msci-world', $pea->instruments[0]->headerKey);

        $opening = $pea->openingRow();
        self::assertNotNull($opening);
        self::assertNull($opening->month);
        self::assertSame(['10.00', '500.00', '510.00'], [$opening->cash?->toString(), $opening->deposits?->toString(), $opening->securities?->toString()]);

        $february = $pea->rowOfMonth(2);
        self::assertNotNull($february);
        self::assertSame(['190.24', '50.00'], array_map(static fn (ParsedMovement $m): string => $m->amount->toString(), $february->buys));
        self::assertSame([['2', '95.12'], ['1', '50']], array_map(static fn (ParsedMovement $m): array => array_map(static fn ($f): string => $f->toString(), $m->factors), $february->buys));
        self::assertSame('wb:2025:02:PEA:D:2', $february->buys[1]->referenceIn(2025)->toString());

        $january = $pea->rowOfMonth(1);
        self::assertNotNull($january);
        self::assertNull($january->securities, 'an empty securities cell is no valuation');
        self::assertSame('100.00', $january->buys[0]->amount->toString());
        self::assertNull($pea->rowOfMonth(12), 'row 16 is the recap and is never read');
    }

    public function testAPeaSheetShiftedByOneRowIsAnchoredOnJanuaryAndTakesTheOpeningFromTheRowAboveIt(): void
    {
        $this->builder->monthSheet('Janvier');
        $sheet = $this->builder->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A4', 'Décembre ')->number('B4', '10')->number('C4', '500')->number('E4', '510');
        foreach (['Janvier ', 'Février ', 'Mars ', 'Avril ', 'Mai ', 'Juin ', 'Juillet ', 'Août ', 'Septembre ', 'Octobre ', 'Novembre ', 'Décembre '] as $index => $name) {
            $row = 5 + $index;
            $sheet->text('A'.$row, $name)->number('B'.$row, (string) (100 + $index))->number('E'.$row, (string) (1000 + $index));
        }
        $sheet->text('A17', 'Total')->number('B17', '999');

        $pea = $this->read()->pea;

        self::assertNotNull($pea);
        $opening = $pea->openingRow();
        self::assertNotNull($opening);
        self::assertSame([4, '10.00', '500.00', '510.00'], [$opening->row, $opening->cash?->toString(), $opening->deposits?->toString(), $opening->securities?->toString()]);
        $december = $pea->rowOfMonth(12);
        self::assertNotNull($december);
        self::assertSame([16, '111.00', '1011.00'], [$december->row, $december->cash?->toString(), $december->securities?->toString()]);
        self::assertSame(5, $pea->rowOfMonth(1)?->row);
        self::assertCount(13, $pea->rows);
        self::assertSame([], $this->issueSummary($this->read()));
    }

    public function testTheRecapRowAfterTheShiftedDecemberIsNeverRead(): void
    {
        $this->builder->monthSheet('Janvier');
        $sheet = $this->builder->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Décembre')->number('B4', '10');
        foreach (['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'] as $index => $name) {
            $sheet->text('A'.(5 + $index), $name)->number('B'.(5 + $index), '1');
        }
        $sheet->text('A17', 'Total')->number('B17', '999')->number('C17', '5');

        $workbook = $this->read();

        self::assertSame([], $this->issueSummary($workbook));
        $pea = $workbook->pea;
        self::assertNotNull($pea);
        self::assertCount(13, $pea->rows);
        self::assertNotContains(17, array_map(static fn ($row): int => $row->row, $pea->rows));
    }

    public function testAPortfolioSheetWithoutAJanuaryRowButWithDataIsAnUnknownLayout(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')->text('D2', 'MSCI World')->text('A3', 'Décembre')->number('B3', '10')->text('A4', 'Février')->number('B4', '20');

        self::assertSame(['UNKNOWN_LAYOUT@PEA!A3'], $this->issueSummary($this->read()));
    }

    public function testValuesBetweenTheHeadersAndTheOpeningRowAreNeverSilentlyDropped(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')->text('D2', 'MSCI World')->number('B3', '7')->text('A5', 'Décembre')->text('A6', 'Janvier')->number('B6', '1');

        self::assertSame(['UNKNOWN_LAYOUT@PEA!A3'], $this->issueSummary($this->read()));
    }

    public function testAJanuaryRowRightUnderTheHeaderLeavesNoOpeningRowAndIsAnUnknownLayout(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')->text('D2', 'MSCI World')->text('A3', 'Janvier')->number('B3', '10');

        self::assertSame(['UNKNOWN_LAYOUT@PEA!A3'], $this->issueSummary($this->read()));
    }

    public function testTheCtoSheetHasOneColumnPerInstrument(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('CTO_TR')
            ->text('D2', 'World ETF')->text('E2', 'Tech ETF')
            ->text('A4', 'Janvier')->number('B4', '5')->number('D4', '100')->formula('E4', '100+50', '150')->number('H4', '1000');

        $cto = $this->read()->ctoTr;

        self::assertNotNull($cto);
        self::assertSame(Block::CTO, $cto->kind);
        self::assertSame(['D', 'E'], array_map(static fn ($i): string => $i->column, $cto->instruments));
        $january = $cto->rowOfMonth(1);
        self::assertNotNull($january);
        self::assertSame(['100.00', '100.00', '50.00'], array_map(static fn (ParsedMovement $m): string => $m->amount->toString(), $january->buys));
        self::assertSame(['D', 'E', 'E'], array_map(static fn (ParsedMovement $m): string => $m->labelKey, $january->buys));
        self::assertSame([1, 1, 2], array_map(static fn (ParsedMovement $m): int => $m->ordinal, $january->buys));
        self::assertSame('1000.00', $january->securities?->toString());
    }

    public function testAPurchaseInAColumnWithoutAHeaderIsAnUnknownLayout(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('CTO_TR')->text('D2', 'World ETF')->text('A4', 'Janvier')->number('F4', '100');

        self::assertSame(['UNKNOWN_LAYOUT@CTO_TR!F4'], $this->issueSummary($this->read()));
    }

    public function testAPortfolioRowWithAnUnknownMonthNameAndValuesIsAnUnknownLayout(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')->text('D2', 'MSCI World')->text('A4', 'Smarch')->number('B4', '5')->text('A5', 'Notes');

        self::assertSame(['UNKNOWN_LAYOUT@PEA!A4'], $this->issueSummary($this->read()));
    }

    public function testAPeaSheetWithoutItsInstrumentHeaderIsAnUnknownLayout(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('PEA')->text('A4', 'Janvier')->number('B4', '5');

        self::assertSame(['UNKNOWN_LAYOUT@PEA!D2'], $this->issueSummary($this->read()));
    }

    public function testASheetThatIsNotReadIsNeverParsed(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->sheet('Recap');
        $this->builder->part('xl/worksheets/sheet2.xml', '<!DOCTYPE x><x/>');

        self::assertSame(['Recap'], $this->read()->ignoredSheets);
    }

    public function testAHostileUsedSheetAbortsTheWholeArchive(): void
    {
        $this->builder->monthSheet('Janvier');
        $this->builder->part('xl/worksheets/sheet1.xml', '<!DOCTYPE x><x/>');

        $this->expectException(ArchiveRefused::class);

        $this->read();
    }
}
