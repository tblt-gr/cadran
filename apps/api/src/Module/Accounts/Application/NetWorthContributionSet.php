<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\NetWorthContribution;

/**
 * The accounts of one workspace and what each of them contributes on every
 * requested business day, resolved in one pass so a curve costs one round
 * trip rather than one per point.
 */
final readonly class NetWorthContributionSet
{
    /**
     * @param list<Account>                             $accounts
     * @param array<string, list<NetWorthContribution>> $byDate   keyed by `Y-m-d`
     */
    public function __construct(
        public array $accounts,
        public array $byDate,
    ) {
    }

    /** @return list<NetWorthContribution> */
    public function on(\DateTimeImmutable $date): array
    {
        return $this->byDate[$date->format('Y-m-d')] ?? [];
    }

    /** @return list<string> */
    public function sourceAccountIds(\DateTimeImmutable $date): array
    {
        $ids = array_map(
            static fn (NetWorthContribution $contribution): string => $contribution->accountId,
            array_filter($this->on($date), static fn (NetWorthContribution $contribution): bool => $contribution->eligible),
        );
        sort($ids, SORT_STRING);

        return $ids;
    }
}
