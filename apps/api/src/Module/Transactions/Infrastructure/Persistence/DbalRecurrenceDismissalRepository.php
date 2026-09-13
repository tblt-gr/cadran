<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDismissalRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RecurrenceDismissalRepository::class)]
final readonly class DbalRecurrenceDismissalRepository implements RecurrenceDismissalRepository
{
    private const string TABLE = 'transaction_recurrence_dismissals';

    public function __construct(private Connection $connection)
    {
    }

    public function fingerprints(WorkspaceScope $workspace): array
    {
        return array_map(TransactionRow::text(...), $this->connection->fetchFirstColumn(
            'SELECT candidate_fingerprint FROM transaction_recurrence_dismissals WHERE workspace_id = :workspace_id ORDER BY candidate_fingerprint',
            ['workspace_id' => $workspace->id],
        ));
    }

    public function dismiss(WorkspaceScope $workspace, string $id, string $fingerprint, \DateTimeImmutable $dismissedAt): void
    {
        $this->connection->insert(self::TABLE, [
            'id' => $id,
            'workspace_id' => $workspace->id,
            'candidate_fingerprint' => $fingerprint,
            'dismissed_at' => $dismissedAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function restore(WorkspaceScope $workspace, string $fingerprint): bool
    {
        return 1 === $this->connection->delete(self::TABLE, [
            'workspace_id' => $workspace->id,
            'candidate_fingerprint' => $fingerprint,
        ]);
    }

    public function findId(WorkspaceScope $workspace, string $fingerprint): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM transaction_recurrence_dismissals WHERE workspace_id = :workspace_id AND candidate_fingerprint = :fingerprint',
            ['workspace_id' => $workspace->id, 'fingerprint' => $fingerprint],
        );

        return false === $id ? null : TransactionRow::text($id);
    }
}
