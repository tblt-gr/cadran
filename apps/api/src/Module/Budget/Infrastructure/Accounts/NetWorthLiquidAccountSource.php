<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Accounts;

use App\Module\Accounts\Application\ResolveNetWorthContributions;
use App\Module\Accounts\Domain\Account;
use App\Module\Budget\Application\EmergencyFund\LiquidAccountSource;
use App\Module\Budget\Domain\EmergencyFund\LiquidAccount;
use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Values eligible accounts through the net-worth resolver, so the fund can never disagree with net worth. */
#[AsAlias(LiquidAccountSource::class)]
final readonly class NetWorthLiquidAccountSource implements LiquidAccountSource
{
    public function __construct(private ResolveNetWorthContributions $netWorth)
    {
    }

    public function eligibleOn(WorkspaceScope $workspace, \DateTimeImmutable $asOf): array
    {
        $set = $this->netWorth->on($workspace, [$asOf]);
        $flagged = [];
        foreach ($set->accounts as $account) {
            if ($account->includeInEmergencyFund) {
                $flagged[$account->id] = $account;
            }
        }
        $eligible = [];
        foreach ($set->on($asOf) as $contribution) {
            $account = $flagged[$contribution->accountId] ?? null;
            if (!$account instanceof Account || !$contribution->eligible) {
                continue;
            }
            $eligible[] = new LiquidAccount(
                $account->id,
                $account->label,
                $contribution->signedValue(),
                $contribution->amount?->asset,
                $contribution->valuedOn,
                $contribution->ageDays,
            );
        }

        return $eligible;
    }
}
