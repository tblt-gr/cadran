<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Domain\AccountCosts;
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
    public function __construct(private Connection $connection, private PortfolioOperationLegStore $legs)
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
            $this->legs->add($operation);
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

    public function activeForAccount(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $asOf = null): array
    {
        $parameters = ['workspace_id' => $workspace->id, 'account_id' => $accountId];
        $date = '';
        if (null !== $asOf) {
            $date = ' AND trade_date <= :as_of';
            $parameters['as_of'] = $asOf->format('Y-m-d');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.PortfolioOperationRow::COLUMNS
            ." FROM portfolio_operations WHERE workspace_id = :workspace_id AND account_id = :account_id AND state = 'ACTIVE'"
            .$date.' ORDER BY trade_date, sequence, id',
            $parameters,
        );

        return $this->legs->hydrateMany($workspace, $rows);
    }

    public function activeOnDate(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $tradeDate): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.PortfolioOperationRow::COLUMNS
            ." FROM portfolio_operations WHERE workspace_id = :workspace_id AND account_id = :account_id AND trade_date = :trade_date AND state = 'ACTIVE'"
            .' ORDER BY sequence, id',
            ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'trade_date' => $tradeDate->format('Y-m-d')],
        );

        return $this->legs->hydrateMany($workspace, $rows);
    }

    public function activeCountsForDays(WorkspaceScope $workspace, array $accountIds, array $tradeDates): array
    {
        if ([] === $accountIds || [] === $tradeDates) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT account_id, trade_date, count(*) AS operation_count FROM portfolio_operations
             WHERE workspace_id = :workspace_id AND state = 'ACTIVE' AND account_id IN (:account_ids) AND trade_date IN (:trade_dates)
             GROUP BY account_id, trade_date",
            [
                'workspace_id' => $workspace->id,
                'account_ids' => $accountIds,
                'trade_dates' => array_map(static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d'), $tradeDates),
            ],
            ['account_ids' => ArrayParameterType::STRING, 'trade_dates' => ArrayParameterType::STRING],
        );
        $counts = [];
        foreach ($rows as $row) {
            $accountId = PortfolioOperationRow::text($row['account_id']);
            $tradeDate = PortfolioOperationRow::text($row['trade_date']);
            $counts[$accountId.'|'.$tradeDate] = (int) PortfolioOperationRow::text($row['operation_count']);
        }

        return $counts;
    }

    public function costsBetween(WorkspaceScope $workspace, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT account_id, fees_value, fees_scale, taxes_value, taxes_scale FROM portfolio_operations
             WHERE workspace_id = :workspace_id AND state = 'ACTIVE' AND trade_date >= :from_date AND trade_date <= :to_date ORDER BY account_id, trade_date, sequence, id",
            ['workspace_id' => $workspace->id, 'from_date' => $from->format('Y-m-d'), 'to_date' => $to->format('Y-m-d')],
        );
        $fees = [];
        $taxes = [];
        foreach ($rows as $row) {
            $accountId = PortfolioOperationRow::text($row['account_id']);
            $fees[$accountId] = ExactDecimal::add($fees[$accountId] ?? DecimalValue::zero(), PortfolioOperationRow::storedDecimal($row, 'fees'));
            $taxes[$accountId] = ExactDecimal::add($taxes[$accountId] ?? DecimalValue::zero(), PortfolioOperationRow::storedDecimal($row, 'taxes'));
        }

        return array_map(
            static fn (string $accountId): AccountCosts => new AccountCosts($accountId, $fees[$accountId], $taxes[$accountId]),
            array_keys($fees),
        );
    }

    public function reorder(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $tradeDate, array $sequences): void
    {
        if ([] === $sequences) {
            return;
        }
        $parameters = ['workspace_id' => $workspace->id, 'account_id' => $accountId, 'trade_date' => $tradeDate->format('Y-m-d'), 'ids' => array_keys($sequences)];
        $types = ['ids' => ArrayParameterType::STRING];
        // Active sequences are unique and positive. Move the selected day above the bounded active range before assigning 1..n.
        $this->connection->executeStatement(
            "UPDATE portfolio_operations SET sequence = sequence + :offset WHERE workspace_id = :workspace_id AND account_id = :account_id AND trade_date = :trade_date AND state = 'ACTIVE' AND id IN (:ids)",
            [...$parameters, 'offset' => PortfolioOperation::MAX_ACTIVE_PER_ACCOUNT + 1],
            [...$types, 'offset' => ParameterType::INTEGER],
        );
        foreach ($sequences as $id => $sequence) {
            $this->connection->update('portfolio_operations', ['sequence' => $sequence], [
                'workspace_id' => $workspace->id,
                'account_id' => $accountId,
                'trade_date' => $tradeDate->format('Y-m-d'),
                'state' => 'ACTIVE',
                'id' => $id,
            ]);
        }
    }

    public function search(WorkspaceScope $workspace, string $accountId, OperationFilters $filters, int $limit, int $offset): array
    {
        [$where, $parameters, $types] = self::criteria($workspace, $accountId, $filters);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.PortfolioOperationRow::COLUMNS.' FROM portfolio_operations WHERE portfolio_operations.workspace_id = :workspace_id AND '.$where
            .(null === $filters->instrumentId ? '' : ' AND (instrument_id = :instrument_id OR counter_instrument_id = :instrument_id OR id IN (SELECT l.operation_id FROM portfolio_operation_legs l WHERE l.workspace_id = :workspace_id AND l.instrument_id = :instrument_id))')
            .' ORDER BY trade_date DESC, sequence DESC, id LIMIT :limit OFFSET :offset',
            [...$parameters, 'limit' => $limit, 'offset' => $offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return $this->legs->hydrateMany($workspace, $rows);
    }

    public function count(WorkspaceScope $workspace, string $accountId, OperationFilters $filters): int
    {
        [$where, $parameters, $types] = self::criteria($workspace, $accountId, $filters);

        return (int) PortfolioOperationRow::text($this->connection->fetchOne(
            'SELECT count(*) FROM portfolio_operations WHERE portfolio_operations.workspace_id = :workspace_id AND '.$where
            .(null === $filters->instrumentId ? '' : ' AND (instrument_id = :instrument_id OR counter_instrument_id = :instrument_id OR id IN (SELECT l.operation_id FROM portfolio_operation_legs l WHERE l.workspace_id = :workspace_id AND l.instrument_id = :instrument_id))'),
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

        return false === $row ? null : $this->legs->hydrateMany($workspace, [$row])[0];
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
            $parameters['instrument_id'] = $filters->instrumentId;
        }

        return [implode(' AND ', $conditions), $parameters, $types];
    }
}
