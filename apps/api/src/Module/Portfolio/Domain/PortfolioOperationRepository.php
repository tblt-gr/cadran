<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;

interface PortfolioOperationRepository
{
    public function find(WorkspaceScope $workspace, string $id): ?PortfolioOperation;

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?PortfolioOperation;

    /** @throws \App\Module\Portfolio\Application\LinkedTransferTaken when an active operation already backs the linked leg */
    public function add(PortfolioOperation $operation): void;

    /** Persists the void markers of an operation; false when the expected version is stale. */
    public function markVoided(PortfolioOperation $voided, int $expectedVersion): bool;

    /** One more than the highest active sequence of the account and trade date. */
    public function nextSequence(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $tradeDate): int;

    public function countActive(WorkspaceScope $workspace, string $accountId): int;

    /**
     * Newest first: trade date, sequence, then identifier, all descending except the identifier.
     *
     * @return list<PortfolioOperation>
     */
    public function search(WorkspaceScope $workspace, string $accountId, OperationFilters $filters, int $limit, int $offset): array;

    public function count(WorkspaceScope $workspace, string $accountId, OperationFilters $filters): int;

    public function activeOperationLinkedTo(WorkspaceScope $workspace, string $transactionId): ?string;

    /**
     * @param list<string> $transactionIds
     *
     * @return list<string> those backing an active operation
     */
    public function linkedAmong(WorkspaceScope $workspace, array $transactionIds): array;

    /**
     * @param list<string> $operationIds
     *
     * @return array<string, string> original identifier => replacement identifier
     */
    public function replacementIds(WorkspaceScope $workspace, array $operationIds): array;
}
