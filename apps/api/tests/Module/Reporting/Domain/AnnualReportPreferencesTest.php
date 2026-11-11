<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Domain;

use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;
use App\Module\Reporting\Domain\AnnualReportPreferences;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class AnnualReportPreferencesTest extends TestCase
{
    public function testAnUnsavedPreferenceStartsWithoutGoalMarks(): void
    {
        $preference = new AnnualReportPreferences(WorkspaceFixture::own(), ['cashIncome'], IncompleteMonths::EXCLUDE, 0);

        self::assertFalse($preference->goalMarks);
    }

    public function testSavingWithoutAGoalChoicePreservesEitherExistingChoice(): void
    {
        foreach ([true, false] as $choice) {
            $preference = new AnnualReportPreferences(WorkspaceFixture::own(), ['cashIncome'], IncompleteMonths::EXCLUDE, 2, goalMarks: $choice);

            $saved = $preference->saved(['budgetExpenses', 'cashIncome'], IncompleteMonths::INCLUDE, new \DateTimeImmutable('2026-11-11T12:00:00Z'));

            self::assertSame($choice, $saved->goalMarks);
            self::assertSame(['budgetExpenses', 'cashIncome'], $saved->columns);
            self::assertSame(IncompleteMonths::INCLUDE, $saved->incompleteMonths);
            self::assertSame(3, $saved->version);
        }
    }

    public function testAnExplicitChoiceCanBeSwitchedInBothDirections(): void
    {
        foreach ([true, false] as $choice) {
            $preference = new AnnualReportPreferences(WorkspaceFixture::own(), ['cashIncome'], IncompleteMonths::EXCLUDE, 1, goalMarks: !$choice);

            self::assertSame($choice, $preference->saved(['cashIncome'], IncompleteMonths::EXCLUDE, new \DateTimeImmutable('2026-11-11T12:00:00Z'), $choice)->goalMarks);
        }
    }
}
