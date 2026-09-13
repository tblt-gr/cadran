<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationPreview;
use App\Module\Transactions\Domain\Categorization\CategorizationPreviewRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategorizationPreviewRepository::class)]
final readonly class DbalCategorizationPreviewRepository implements CategorizationPreviewRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(CategorizationPreview $preview): void
    {
        $this->connection->insert('transaction_categorization_previews', [
            'id' => $preview->id, 'workspace_id' => $preview->workspace->id, 'preview_token' => $preview->token,
            'rule_id' => $preview->ruleId, 'from_date' => $preview->from->format('Y-m-d'), 'to_date' => $preview->to->format('Y-m-d'),
            'created_at' => $preview->createdAt->format('Y-m-d H:i:s.uP'), 'expires_at' => $preview->expiresAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function findLiveForUpdate(WorkspaceScope $workspace, string $token, \DateTimeImmutable $now): ?CategorizationPreview
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, workspace_id, preview_token, rule_id, from_date, to_date, created_at, expires_at FROM transaction_categorization_previews '
            .'WHERE workspace_id = :workspace_id AND preview_token = :token AND consumed_at IS NULL AND expires_at > :now ORDER BY created_at DESC LIMIT 1 FOR UPDATE',
            ['workspace_id' => $workspace->id, 'token' => $token, 'now' => $now->format('Y-m-d H:i:s.uP')],
        );
        if (false === $row) {
            return null;
        }

        return new CategorizationPreview(
            TransactionRow::text($row['id'] ?? null), $workspace, TransactionRow::text($row['preview_token'] ?? null),
            null === ($row['rule_id'] ?? null) ? null : TransactionRow::text($row['rule_id']),
            new \DateTimeImmutable(TransactionRow::text($row['from_date'] ?? null)), new \DateTimeImmutable(TransactionRow::text($row['to_date'] ?? null)),
            new \DateTimeImmutable(TransactionRow::text($row['created_at'] ?? null)), new \DateTimeImmutable(TransactionRow::text($row['expires_at'] ?? null)),
        );
    }

    public function markTokenConsumed(WorkspaceScope $workspace, string $token, \DateTimeImmutable $consumedAt): void
    {
        $this->connection->executeStatement(
            'UPDATE transaction_categorization_previews SET consumed_at = :consumed_at WHERE workspace_id = :workspace_id AND preview_token = :token AND consumed_at IS NULL',
            ['consumed_at' => $consumedAt->format('Y-m-d H:i:s.uP'), 'workspace_id' => $workspace->id, 'token' => $token],
        );
    }

    public function purgeExpired(WorkspaceScope $workspace, \DateTimeImmutable $now): void
    {
        // Previews are short-lived and contain no financial values. Keeping only live previews also bounds storage.
        $this->connection->executeStatement('DELETE FROM transaction_categorization_previews WHERE workspace_id = :workspace_id AND expires_at <= :now', ['workspace_id' => $workspace->id, 'now' => $now->format('Y-m-d H:i:s.uP')]);
    }
}
