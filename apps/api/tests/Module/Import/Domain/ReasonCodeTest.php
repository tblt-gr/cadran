<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Issue;
use App\Module\Import\Domain\ReasonCode;
use PHPUnit\Framework\TestCase;

final class ReasonCodeTest extends TestCase
{
    public function testOnlyFourCodesExcludeWithoutRejecting(): void
    {
        $excluded = array_filter(ReasonCode::cases(), static fn (ReasonCode $code): bool => !$code->isRejection());

        self::assertEqualsCanonicalizing(
            [ReasonCode::LABEL_WITHOUT_AMOUNT, ReasonCode::ZERO_AMOUNT, ReasonCode::MONTH_NOT_ENDED, ReasonCode::SKIPPED_BY_MAPPING],
            array_values($excluded),
        );
    }

    public function testTheDocumentedCodesAreTheOnlyOnes(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'UNKNOWN_LAYOUT', 'UNKNOWN_SHEET_NAME', 'UNMAPPED_LABEL', 'UNMAPPED_ACCOUNT', 'AMOUNT_WITHOUT_LABEL',
                'LABEL_WITHOUT_AMOUNT', 'ZERO_AMOUNT', 'FORMULA_NOT_SUPPORTED', 'FORMULA_REFERENCE', 'CACHED_VALUE_MISMATCH',
                'PRECISION_EXCEEDED', 'NEGATIVE_INCOME', 'UNLINKABLE_REFUND', 'AMBIGUOUS_QUANTITY',
                'ACCOUNT_OPENED_AFTER_DATE', 'PERIOD_CLOSED', 'YEAR_ALREADY_IMPORTED', 'LATER_YEAR_ALREADY_IMPORTED',
                'ARCHIVE_REFUSED', 'MONTH_NOT_ENDED', 'SKIPPED_BY_MAPPING',
            ],
            array_map(static fn (ReasonCode $code): string => $code->value, ReasonCode::cases()),
        );
    }

    public function testAnIssueLocatesAReasonWithoutQuotingContent(): void
    {
        $issue = new Issue(ReasonCode::ZERO_AMOUNT, 'Janvier', 'B3');

        self::assertSame('Janvier', $issue->sheet);
        self::assertSame('B3', $issue->cell);
        self::assertFalse($issue->isRejection());
        self::assertTrue((new Issue(ReasonCode::UNKNOWN_LAYOUT, null, null))->isRejection());
    }

    public function testAnIssueMayOverrideTheDefaultOfItsCodeSoAMonthNotEndedIsExcludedOrRejected(): void
    {
        self::assertFalse((new Issue(ReasonCode::MONTH_NOT_ENDED, 'Novembre', null))->isRejection());
        self::assertTrue((new Issue(ReasonCode::MONTH_NOT_ENDED, 'Novembre', null, rejection: true))->isRejection());
    }
}
