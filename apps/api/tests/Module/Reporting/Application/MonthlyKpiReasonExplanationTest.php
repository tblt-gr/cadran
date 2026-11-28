<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Accounts\Domain\NetWorthReason;
use App\Module\Reporting\Application\MonthlyKpiReasonExplanation;
use App\Module\Reporting\Domain\MonthlyProjectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyKpiReasonExplanationTest extends TestCase
{
    private const string GENERIC = 'The metric cannot be calculated for a reason this version does not describe.';

    #[DataProvider('producedReasons')]
    public function testEveryReasonAMonthlyKpiCanCarryHasItsOwnSentence(string $reason): void
    {
        $sentence = MonthlyKpiReasonExplanation::for($reason);

        self::assertNotNull($sentence);
        self::assertNotSame(self::GENERIC, $sentence);
    }

    /** @return iterable<string, array{string}> */
    public static function producedReasons(): iterable
    {
        foreach (MonthlyProjectionReason::cases() as $reason) {
            yield $reason->value => [$reason->value];
        }
        foreach ([NetWorthReason::MISSING_VALUATION, NetWorthReason::NO_ELIGIBLE_ACCOUNT, NetWorthReason::MIXED_ASSETS] as $reason) {
            yield 'net worth '.$reason->value => [$reason->value];
        }
    }

    public function testAnUnlistedReasonGetsTheGenericSentence(): void
    {
        self::assertSame(self::GENERIC, MonthlyKpiReasonExplanation::for('SOME_FUTURE_REASON'));
    }

    public function testACalculableKpiHasNoSentence(): void
    {
        self::assertNull(MonthlyKpiReasonExplanation::for(null));
    }
}
