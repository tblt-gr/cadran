<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountOverviewCompositionChartReason;
use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * Hand-computed: Courant 1 000, PEA 3 000 and a loan of 200 sum to 3 800;
 * the loan is the only negative component. The reasons are checked in the
 * documented order: missing valuation, mixed assets, zero total, negative
 * total, negative component.
 */
final class AccountOverviewCompositionChartReasonTest extends TestCase
{
    public function testAllPositiveComponentsNeedNoReason(): void
    {
        self::assertNull(AccountOverviewCompositionChartReason::of([$this->input('a', '1000'), $this->input('b', '3000')]));
    }

    public function testNoAccountNeedsNoReason(): void
    {
        self::assertNull(AccountOverviewCompositionChartReason::of([]));
    }

    public function testALiabilityIsANegativeComponent(): void
    {
        $reason = AccountOverviewCompositionChartReason::of([$this->input('a', '1000'), $this->input('b', '3000'), $this->input('c', '200', -1)]);

        self::assertSame(AccountOverviewCompositionChartReason::NEGATIVE_COMPONENT, $reason);
    }

    public function testAMissingValueWinsOverEveryOtherReason(): void
    {
        $reason = AccountOverviewCompositionChartReason::of([
            $this->input('a', null),
            $this->input('b', '10', asset: 'BTC'),
            $this->input('c', '500', -1),
        ]);

        self::assertSame(AccountOverviewCompositionChartReason::MISSING_VALUATION, $reason);
    }

    public function testMixedAssetsWinOverTheTotalChecks(): void
    {
        $reason = AccountOverviewCompositionChartReason::of([$this->input('a', '10'), $this->input('b', '10', -1, 'BTC')]);

        self::assertSame(AccountOverviewCompositionChartReason::MIXED_ASSETS, $reason);
    }

    public function testAZeroTotalIsNotAnEmptyPie(): void
    {
        $reason = AccountOverviewCompositionChartReason::of([$this->input('a', '200'), $this->input('b', '200', -1)]);

        self::assertSame(AccountOverviewCompositionChartReason::ZERO_TOTAL, $reason);
    }

    public function testAZeroValuedAccountAloneIsAZeroTotal(): void
    {
        self::assertSame(AccountOverviewCompositionChartReason::ZERO_TOTAL, AccountOverviewCompositionChartReason::of([$this->input('a', '0.00')]));
    }

    public function testANegativeTotalWinsOverTheNegativeComponent(): void
    {
        $reason = AccountOverviewCompositionChartReason::of([$this->input('a', '100'), $this->input('b', '250', -1)]);

        self::assertSame(AccountOverviewCompositionChartReason::NEGATIVE_TOTAL, $reason);
    }

    public function testVeryLargeDecimalsStayExact(): void
    {
        $big = '99999999999999999999999999.999999999999999999999999';
        $reason = AccountOverviewCompositionChartReason::of([$this->input('a', $big), $this->input('b', $big, -1)]);

        self::assertSame(AccountOverviewCompositionChartReason::ZERO_TOTAL, $reason);
    }

    private function input(string $id, ?string $value, int $sign = 1, string $asset = 'EUR'): AccountShareInput
    {
        return new AccountShareInput(
            accountId: $id,
            includeInNetWorth: true,
            netWorthSign: $sign,
            primaryGroupId: 'g',
            value: null === $value ? null : DecimalValue::fromString($value),
            asset: AssetCode::fromString($asset),
        );
    }
}
