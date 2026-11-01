<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountOverviewCompositionTooLarge;
use App\Module\Accounts\Application\AccountOverviewCompositionView;
use App\Module\Accounts\Application\ComposeAccountOverviewComposition;
use App\Module\Accounts\Application\OverviewFigures;
use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthReason;
use App\Module\Accounts\Domain\NetWorthShares;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use PHPUnit\Framework\TestCase;

/** The bound on eligible accounts, reached without seeding a database. */
final class ComposeAccountOverviewCompositionTest extends TestCase
{
    public function testMoreThanAThousandEligibleAccountsAreRefused(): void
    {
        $this->expectException(AccountOverviewCompositionTooLarge::class);

        $this->compose($this->figures(1001));
    }

    public function testExactlyAThousandEligibleAccountsAreRead(): void
    {
        $view = $this->compose($this->figures(1000));

        self::assertSame('2026-09-05', $view->asOf);
    }

    public function testIneligibleAccountsDoNotCountAgainstTheBound(): void
    {
        $view = $this->compose($this->figures(1000, ineligible: 50));

        self::assertSame([], $view->accounts);
    }

    private function compose(OverviewFigures $figures): AccountOverviewCompositionView
    {
        $catalog = new class implements AssetCatalog {
            public function findByCode(AssetCode $code): ?Asset
            {
                return null;
            }

            public function readPage(int $limit, int $offset): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }
        };

        return (new ComposeAccountOverviewComposition($catalog))($figures);
    }

    private function figures(int $eligible, int $ineligible = 0): OverviewFigures
    {
        $day = new \DateTimeImmutable('2026-09-05');
        $contributions = [];
        for ($index = 0; $index < $eligible + $ineligible; ++$index) {
            $contributions[] = new NetWorthContribution(
                accountId: sprintf('00000000-0000-7000-8000-%012d', $index),
                netWorthSign: 1,
                primaryGroupId: null,
                amount: null,
                quality: ValuationQuality::MISSING,
                ageDays: null,
                valuedOn: null,
                eligible: $index < $eligible,
            );
        }

        return new OverviewFigures(
            day: $day,
            netWorth: new NetWorth($day, null, null, NetWorthReason::MISSING_VALUATION, ValuationQuality::MISSING, null, $eligible, 0, $eligible, 0),
            groups: [],
            lineages: [],
            shares: new NetWorthShares([], []),
            rollup: [],
            direct: [],
            ungroupedTotal: null,
            contributions: $contributions,
        );
    }
}
