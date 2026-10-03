<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Valuation;

use App\Module\Foundation\Domain\WorkspaceScope;

interface ValuationRepository
{
    /** A valuation with its position snapshots, active or superseded. */
    public function find(WorkspaceScope $workspace, string $id): ?Valuation;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?Valuation;

    public function findActive(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $valuedOn): ?Valuation;

    /** The latest active valuation strictly before a day, optionally restricted to known totals or an explicit completeness. */
    public function latestActiveBefore(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day, bool $withTotalOnly = false, ?ValuationCompleteness $completeness = null): ?Valuation;

    public function add(Valuation $valuation): void;

    /** Persists the supersession marker only; false when the expected version is stale. */
    public function markSuperseded(Valuation $superseded, int $expectedVersion): bool;

    public function countActive(WorkspaceScope $workspace, string $accountId): int;

    /**
     * Active valuations, newest first.
     *
     * @return list<Valuation>
     */
    public function search(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int;

    /**
     * Non-null prices of the snapshots of active valuations of the account dated on or before a day.
     *
     * @param list<string> $instrumentIds
     *
     * @return list<ObservedPrice>
     */
    public function observedPrices(WorkspaceScope $workspace, string $accountId, array $instrumentIds, \DateTimeImmutable $onOrBefore): array;
}
