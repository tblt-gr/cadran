<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\ValuationQuality;

/**
 * One eligible account whose usable valuation predates the day it is read on:
 * the source behind a stale-valuation count, so the count can be audited.
 */
final readonly class StaleValuationView
{
    public function __construct(
        public string $accountId,
        public string $label,
        public ?string $institution,
        public string $kind,
        public string $valuationMode,
        public ?string $valuedOn,
        public ?int $ageDays,
    ) {
    }

    /**
     * Exactly the contributions the stale count is made of, oldest valuation first.
     *
     * @param list<Account>              $accounts
     * @param list<NetWorthContribution> $contributions
     *
     * @return list<self>
     */
    public static function listFor(array $accounts, array $contributions): array
    {
        $byId = [];
        foreach ($accounts as $account) {
            $byId[$account->id] = $account;
        }

        $rows = [];
        foreach ($contributions as $contribution) {
            $account = $byId[$contribution->accountId] ?? null;
            if (!$contribution->eligible || ValuationQuality::STALE !== $contribution->quality || !$account instanceof Account) {
                continue;
            }

            $rows[] = new self(
                $account->id,
                $account->label,
                $account->institution,
                $account->kind->value,
                $account->valuationMode->value,
                $contribution->valuedOn?->format('Y-m-d'),
                $contribution->ageDays,
            );
        }

        usort($rows, static fn (self $a, self $b): int => [$b->ageDays ?? -1, $a->label, $a->accountId] <=> [$a->ageDays ?? -1, $b->label, $b->accountId]);

        return $rows;
    }
}
