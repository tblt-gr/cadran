<?php

declare(strict_types=1);

namespace App\Module\Reporting\Infrastructure\Persistence;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthSnapshotStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MonthSnapshotStore::class)]
final readonly class DbalMonthSnapshotStore implements MonthSnapshotStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, CalendarMonth $month, string $closureId): ?MonthFigures
    {
        $payload = $this->connection->fetchOne(
            'SELECT payload FROM reporting_month_snapshots
             WHERE workspace_id = :workspace_id AND month = :month AND closure_id = :closure_id AND schema_version = :schema_version',
            [
                'workspace_id' => $workspace->id,
                'month' => $month->key(),
                'closure_id' => $closureId,
                'schema_version' => MonthFigures::SCHEMA_VERSION,
            ],
        );
        if (!is_string($payload)) {
            return null;
        }
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? MonthFigures::fromPayload($decoded) : null;
        } catch (\JsonException|\UnexpectedValueException) {
            // An unreadable snapshot is recaptured rather than served.
            return null;
        }
    }

    public function capture(WorkspaceScope $workspace, CalendarMonth $month, string $closureId, MonthFigures $figures, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'INSERT INTO reporting_month_snapshots (workspace_id, month, closure_id, policy_version, schema_version, payload, captured_at)
             VALUES (:workspace_id, :month, :closure_id, :policy_version, :schema_version, :payload, :captured_at)
             ON CONFLICT (workspace_id, month, closure_id) DO UPDATE
             SET policy_version = EXCLUDED.policy_version, schema_version = EXCLUDED.schema_version,
                 payload = EXCLUDED.payload, captured_at = EXCLUDED.captured_at
             WHERE reporting_month_snapshots.schema_version <> EXCLUDED.schema_version',
            [
                'workspace_id' => $workspace->id,
                'month' => $month->key(),
                'closure_id' => $closureId,
                'policy_version' => $figures->policyVersion,
                'schema_version' => MonthFigures::SCHEMA_VERSION,
                'payload' => json_encode($figures->toPayload(), JSON_THROW_ON_ERROR),
                'captured_at' => $at->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    public function deleteForMonth(WorkspaceScope $workspace, CalendarMonth $month): void
    {
        $this->connection->executeStatement(
            'DELETE FROM reporting_month_snapshots WHERE workspace_id = :workspace_id AND month = :month',
            ['workspace_id' => $workspace->id, 'month' => $month->key()],
        );
    }
}
