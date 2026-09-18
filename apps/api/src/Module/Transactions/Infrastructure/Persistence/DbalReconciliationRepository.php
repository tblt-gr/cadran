<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Reconciliation\ReconciliationRepository;
use App\Module\Transactions\Domain\Reconciliation\TransactionReconciliation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReconciliationRepository::class)]
final readonly class DbalReconciliationRepository implements ReconciliationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function recordCandidates(WorkspaceScope $workspace, string $transactionId, array $candidateTransactionIds, \DateTimeImmutable $at): void
    {
        foreach ($candidateTransactionIds as $candidateTransactionId) {
            $this->connection->insert('transaction_reconciliation_candidates', [
                'workspace_id' => $workspace->id,
                'transaction_id' => $transactionId,
                'candidate_transaction_id' => $candidateTransactionId,
                'created_at' => $at->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    public function candidateIds(WorkspaceScope $workspace, string $transactionId): array
    {
        return $this->candidateIdsByTransactionIds($workspace, [$transactionId])[$transactionId] ?? [];
    }

    public function candidateIdsByTransactionIds(WorkspaceScope $workspace, array $transactionIds): array
    {
        if ([] === $transactionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT transaction_id, candidate_transaction_id FROM transaction_reconciliation_candidates '
            .'WHERE workspace_id = :workspace_id AND transaction_id IN (:transaction_ids) '
            .'ORDER BY transaction_id, candidate_transaction_id',
            ['workspace_id' => $workspace->id, 'transaction_ids' => $transactionIds],
            ['transaction_ids' => ArrayParameterType::STRING],
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[TransactionRow::text($row['transaction_id'] ?? null)][] = TransactionRow::text($row['candidate_transaction_id'] ?? null);
        }

        return $grouped;
    }

    public function clearCandidates(WorkspaceScope $workspace, string $transactionId): void
    {
        $this->connection->delete('transaction_reconciliation_candidates', [
            'workspace_id' => $workspace->id,
            'transaction_id' => $transactionId,
        ]);
    }

    public function removeCandidateEverywhere(WorkspaceScope $workspace, string $candidateTransactionId): void
    {
        $this->connection->delete('transaction_reconciliation_candidates', [
            'workspace_id' => $workspace->id,
            'candidate_transaction_id' => $candidateTransactionId,
        ]);
    }

    public function link(TransactionReconciliation $reconciliation): void
    {
        $this->connection->insert('transaction_reconciliations', [
            'id' => $reconciliation->id,
            'workspace_id' => $reconciliation->workspace->id,
            'reviewed_transaction_id' => $reconciliation->reviewedTransactionId,
            'matched_transaction_id' => $reconciliation->matchedTransactionId,
            'created_at' => $reconciliation->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function matchedIdsByReviewedTransactionIds(WorkspaceScope $workspace, array $reviewedTransactionIds): array
    {
        if ([] === $reviewedTransactionIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT reviewed_transaction_id, matched_transaction_id FROM transaction_reconciliations '
            .'WHERE workspace_id = :workspace_id AND reviewed_transaction_id IN (:reviewed_transaction_ids)',
            ['workspace_id' => $workspace->id, 'reviewed_transaction_ids' => $reviewedTransactionIds],
            ['reviewed_transaction_ids' => ArrayParameterType::STRING],
        );
        $matched = [];
        foreach ($rows as $row) {
            $matched[TransactionRow::text($row['reviewed_transaction_id'] ?? null)] = TransactionRow::text($row['matched_transaction_id'] ?? null);
        }

        return $matched;
    }

    public function findByReviewedTransactionId(WorkspaceScope $workspace, string $reviewedTransactionId): ?TransactionReconciliation
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, reviewed_transaction_id, matched_transaction_id, created_at FROM transaction_reconciliations '
            .'WHERE workspace_id = :workspace_id AND reviewed_transaction_id = :reviewed_transaction_id',
            ['workspace_id' => $workspace->id, 'reviewed_transaction_id' => $reviewedTransactionId],
        );

        return false === $row ? null : new TransactionReconciliation(
            TransactionRow::text($row['id'] ?? null),
            $workspace,
            TransactionRow::text($row['reviewed_transaction_id'] ?? null),
            TransactionRow::text($row['matched_transaction_id'] ?? null),
            new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)),
        );
    }
}
