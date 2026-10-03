<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Domain\OperationFilters;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PortfolioOperationRepository::class)]
final readonly class DbalPortfolioOperationRepository implements PortfolioOperationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?PortfolioOperation
    {
        return $this->one($workspace, $id, false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?PortfolioOperation
    {
        return $this->one($workspace, $id, true);
    }

    public function add(PortfolioOperation $operation): void
    {
        try {
            $this->connection->insert('portfolio_operations', [
                'workspace_id' => $operation->workspace->id,
                ...PortfolioOperationRow::columns($operation),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // The account lock serialises writers, so the only unique rule a request can still trip is the link.
            if (str_contains($exception->getMessage(), 'portfolio_operations_active_link_unique')) {
                throw new LinkedTransferTaken('The transfer leg already backs an active operation.', previous: $exception);
            }

            throw $exception;
        }
    }

    public function markVoided(PortfolioOperation $voided, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update(
            'portfolio_operations',
            PortfolioOperationRow::voidColumns($voided),
            ['workspace_id' => $voided->workspace->id, 'id' => $voided->id, 'version' => $expectedVersion],
        );
    }

    public function nextSequence(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $tradeDate): int
    {
        $highest = $this->connection->fetchOne(
            "SELECT max(sequence) FROM portfolio_operations WHERE workspace_id = :workspace_id AND account_id = :account_id AND trade_date = :trade_date AND state = 'ACTIVE'",
            ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'trade_date' => $tradeDate->format('Y-m-d')],
        );

        return (null === $highest || false === $highest ? 0 : (int) PortfolioOperationRow::text($highest)) + 1;
    }

    public function countActive(WorkspaceScope $workspace, string $accountId): int
    {
        return (int) PortfolioOperationRow::text($this->connection->fetchOne(
            "SELECT count(*) FROM portfolio_operations WHERE workspace_id = :workspace_id AND account_id = :account_id AND state = 'ACTIVE'",
            ['workspace_id' => $workspace->id, 'account_id' => $accountId],
        ));
    }

    public function search(WorkspaceScope $workspace, string $accountId, OperationFilters $filters, int $limit, int $offset): array
    {
        [$where, $parameters, $types] = self::criteria($workspace, $accountId, $filters);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.PortfolioOperationRow::COLUMNS.' FROM portfolio_operations WHERE workspace_id = :workspace_id AND '.$where
            .' ORDER BY trade_date DESC, sequence DESC, id LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): PortfolioOperation => PortfolioOperationRow::hydrate($row, $workspace), $rows);
    }

    public function count(WorkspaceScope $workspace, string $accountId, OperationFilters $filters): int
    {
        [$where, $parameters, $types] = self::criteria($workspace, $accountId, $filters);

        return (int) PortfolioOperationRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM portfolio_operations WHERE workspace_id = :workspace_id AND '.$where,
            $parameters,
            $types,
        ));
    }

    public function activeOperationLinkedTo(WorkspaceScope $workspace, string $transactionId): ?string
    {
        $id = $this->connection->fetchOne(
            "SELECT id FROM portfolio_operations WHERE workspace_id = :workspace_id AND linked_transaction_id = :transaction_id AND state = 'ACTIVE'",
            ['workspace_id' => $workspace->id, 'transaction_id' => $transactionId],
        );

        return false === $id ? null : PortfolioOperationRow::text($id);
    }

    public function linkedAmong(WorkspaceScope $workspace, array $transactionIds): array
    {
        if ([] === $transactionIds) {
            return [];
        }
        $rows = $this->connection->fetchFirstColumn(
            "SELECT linked_transaction_id FROM portfolio_operations WHERE workspace_id = :workspace_id AND state = 'ACTIVE' AND linked_transaction_id IN (:ids)",
            ['workspace_id' => $workspace->id, 'ids' => $transactionIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_map(PortfolioOperationRow::text(...), $rows);
    }

    public function replacementIds(WorkspaceScope $workspace, array $operationIds): array
    {
        if ([] === $operationIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, corrects_operation_id FROM portfolio_operations WHERE workspace_id = :workspace_id AND corrects_operation_id IN (:ids)',
            ['workspace_id' => $workspace->id, 'ids' => $operationIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $map = [];
        foreach ($rows as $row) {
            $map[PortfolioOperationRow::text($row['corrects_operation_id'])] = PortfolioOperationRow::text($row['id']);
        }

        return $map;
    }

    private function one(WorkspaceScope $workspace, string $id, bool $forUpdate): ?PortfolioOperation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.PortfolioOperationRow::COLUMNS.' FROM portfolio_operations WHERE workspace_id = :workspace_id AND id = :id'.($forUpdate ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, 'id' => $id],
        );

        return false === $row ? null : PortfolioOperationRow::hydrate($row, $workspace);
    }

    /** @return array{string, array<string, mixed>, array<string, ArrayParameterType>} */
    private static function criteria(WorkspaceScope $workspace, string $accountId, OperationFilters $filters): array
    {
        $conditions = ['account_id = :account_id'];
        $parameters = ['workspace_id' => $workspace->id, 'account_id' => $accountId];
        $types = [];
        if (!$filters->includeVoided) {
            $conditions[] = "state = 'ACTIVE'";
        }
        if (null !== $filters->from) {
            $conditions[] = 'trade_date >= :from_date';
            $parameters['from_date'] = $filters->from->format('Y-m-d');
        }
        if (null !== $filters->to) {
            $conditions[] = 'trade_date <= :to_date';
            $parameters['to_date'] = $filters->to->format('Y-m-d');
        }
        if ([] !== $filters->types) {
            $conditions[] = 'operation_type IN (:operation_types)';
            $parameters['operation_types'] = array_map(static fn ($type): string => $type->value, $filters->types);
            $types['operation_types'] = ArrayParameterType::STRING;
        }
        if (null !== $filters->instrumentId) {
            $conditions[] = '(instrument_id = :instrument_id OR counter_instrument_id = :instrument_id)';
            $parameters['instrument_id'] = $filters->instrumentId;
        }

        return [implode(' AND ', $conditions), $parameters, $types];
    }
}
