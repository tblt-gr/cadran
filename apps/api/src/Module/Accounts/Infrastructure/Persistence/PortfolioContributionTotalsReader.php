<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\ContributionTotalsReader;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\ReadCumulativeContributions;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ContributionTotalsReader::class)]
final readonly class PortfolioContributionTotalsReader implements ContributionTotalsReader
{
    public function __construct(
        private AccountRepository $accounts,
        private ReadCumulativeContributions $contributions,
    ) {
    }

    public function cumulative(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $asOf): ?AssetAmount
    {
        $account = $this->accounts->find($workspace, $accountId);
        if (null === $account || AccountValuationMode::PORTFOLIO !== $account->valuationMode) {
            return null;
        }

        return new AssetAmount(($this->contributions)($workspace, $accountId, $asOf), $account->assetCode);
    }
}
