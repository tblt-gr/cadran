<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\Plan\ImportPlan;
use App\Tests\Module\Import\Support\PlannerTestCase;

/** The per-month `cached_cells` and `skip_cells` overrides of the mapping, on synthetic workbooks. */
final class ImportPlannerCellOverridesTest extends PlannerTestCase
{
    private static function mappingWith(string $overrides): string
    {
        return self::MAPPING."\noverrides:\n".$overrides."\n";
    }

    /**
     * @return list<string> `CODE@sheet!cell` of every warning
     */
    private static function warnings(ImportPlan $plan): array
    {
        return array_map(static fn ($warning): string => sprintf('%s@%s!%s', $warning->code, $warning->sheet, $warning->cell), $plan->warnings);
    }

    public function testAFormulaWithAReferenceIsRefusedWithoutTheOverride(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->formula('B2', '2500.00-B3', '2350.00');

        self::assertSame(['FORMULA_REFERENCE@Janvier!B2'], self::issues($this->plan()));
    }

    public function testACachedCellIsOneMovementEqualToItsStoredValueAndWarns(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->formula('B2', '2500.00-B4', '2350.00')->text('A3', 'Salary')->number('B3', '150');

        $plan = $this->plan(self::mappingWith('  "2025-01": { cached_cells: ["B2"] }'));

        self::assertSame([], $plan->issues);
        self::assertSame(['INCOME 2025-01-31 2350.00 wb:2025:01:INC:salary:1', 'INCOME 2025-01-31 150.00 wb:2025:01:INC:salary:2'], self::describe($plan));
        self::assertSame(['CACHED_VALUE_USED@Janvier!B2'], self::warnings($plan));
    }

    public function testACachedSavingsCellFollowsTheSignRules(): void
    {
        $this->workbook->monthSheet('Janvier')->text('G2', 'Savings A')->formula('H2', '-B7', '-300');

        $plan = $this->plan(self::mappingWith('  "2025-01": { cached_cells: ["H2"] }'));

        self::assertSame([], $plan->issues);
        self::assertSame(['TRANSFER 2025-01-31 300.00 acc-Savings A>acc-Main current account'], self::describe($plan));
    }

    public function testACachedCellKeepsTheFloatArtifactToleranceAndItsWarning(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->formula('B2', '1+B9', '90.4899999999998');

        $plan = $this->plan(self::mappingWith('  "2025-01": { cached_cells: ["B2"] }'));

        self::assertSame(['INCOME 2025-01-31 90.49 wb:2025:01:INC:salary:1'], self::describe($plan));
        self::assertSame(['CACHED_VALUE_USED@Janvier!B2', 'FLOAT_ARTIFACT_NORMALIZED@Janvier!B2'], self::warnings($plan));
    }

    public function testACachedCellReadsLikeAPlainNumberForPrecisionZeroAndMissingValue(): void
    {
        $this->workbook->monthSheet('Janvier')
            ->text('A2', 'Salary')->formula('B2', '1+B9', '10.005')
            ->text('A3', 'Salary')->formula('B3', '1+B9', '0')
            ->text('A4', 'Salary')->formula('B4', '1+B9', null)
            ->text('A5', 'Salary')->formula('B5', '1+B9', '-5');

        $plan = $this->plan(self::mappingWith('  "2025-01": { cached_cells: ["B2", "B3", "B4", "B5"] }'));

        self::assertSame(
            ['PRECISION_EXCEEDED@Janvier!B2', 'ZERO_AMOUNT@Janvier!B3', 'CACHED_VALUE_MISMATCH@Janvier!B4', 'NEGATIVE_INCOME@Janvier!B5'],
            self::issues($plan),
        );
    }

    public function testASkippedCellIsExcludedWholeAndReportedWithoutAReasonOrAnAmount(): void
    {
        $this->workbook->monthSheet('Janvier')->text('G2', 'Savings A')->formula('H2', '-B7', '-300');

        $plan = $this->plan(self::mappingWith('  "2025-01": { skip_cells: { "H2": "Duplicate of another line" } }'));

        self::assertSame(['SKIPPED_BY_MAPPING@Janvier!H2'], self::issues($plan));
        self::assertFalse($plan->hasRejections());
        self::assertSame([], $plan->steps);
        self::assertSame('SKIPPED_BY_MAPPING', $plan->exclusions()[0]->code->value);
    }

    public function testASkippedRowDoesNotConsumeAnOrdinalAndNeedsNoMapping(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Unmapped thing')->number('B2', '9')->text('A3', 'Salary')->number('B3', '150');

        $plan = $this->plan(self::mappingWith('  "2025-01": { skip_cells: { "B2": "Not income" } }'));

        self::assertSame(['SKIPPED_BY_MAPPING@Janvier!B2'], self::issues($plan));
        self::assertSame(['INCOME 2025-01-31 150.00 wb:2025:01:INC:salary:1'], self::describe($plan));
    }

    public function testAnOverrideForAMonthOfAnotherYearIsIgnored(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '1');

        $plan = $this->plan(self::mappingWith('  "2024-08": { cached_cells: ["B2"] }'));

        self::assertSame([], $plan->issues);
    }

    public function testAnOverrideForAMonthMissingFromTheWorkbookIsAMappingProblem(): void
    {
        $this->workbook->monthSheet('Janvier')->text('A2', 'Salary')->number('B2', '1');

        try {
            $this->plan(self::mappingWith('  "2025-03": { cached_cells: ["B2"] }'));
            self::fail('The mapping must be refused.');
        } catch (InvalidMapping $invalid) {
            self::assertSame(['overrides.2025-03'], array_map(static fn ($problem): string => $problem->location, $invalid->problems));
        }
    }
}
