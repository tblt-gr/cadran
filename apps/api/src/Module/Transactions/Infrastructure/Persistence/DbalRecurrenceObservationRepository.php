<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservation;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationRepository;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationWindow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RecurrenceObservationRepository::class)]
final readonly class DbalRecurrenceObservationRepository implements RecurrenceObservationRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function window(WorkspaceScope $workspace, \DateTimeImmutable $from, int $limit): RecurrenceObservationWindow
    {
        // The grouping key is computed here so grouping and the database agree
        // on one normalisation; `normalized_label` is the stored generated
        // column, so a label is never normalised twice in two different ways.
        // One row beyond the cap is read to learn whether the window was cut,
        // and the most recent rows are the ones kept: a truncated scan that
        // dropped the latest months would propose nothing useful.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, account_id, booked_on, amount_value, amount_scale, asset_code, '
            .'coalesce(lower(counterparty), normalized_label) AS grouping_key, '
            .'coalesce(counterparty, raw_label) AS display_name '
            .'FROM transaction_transactions '
            ."WHERE workspace_id = :workspace_id AND state NOT IN ('VOIDED', 'REJECTED') "
            ."AND nature IN ('INCOME', 'EXPENSE', 'FEE') AND booked_on >= :from_date "
            .'ORDER BY booked_on DESC, id DESC LIMIT :limit',
            ['workspace_id' => $workspace->id, 'from_date' => $from->format('Y-m-d'), 'limit' => $limit + 1],
            ['limit' => ParameterType::INTEGER],
        );

        $partial = count($rows) > $limit;

        return new RecurrenceObservationWindow(
            $workspace,
            array_map(
                fn (array $row): RecurrenceObservation => $this->hydrate($workspace, $row),
                array_slice($rows, 0, $limit),
            ),
            $partial,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(WorkspaceScope $workspace, array $row): RecurrenceObservation
    {
        return new RecurrenceObservation(
            TransactionRow::text($row['id'] ?? null),
            $workspace,
            TransactionRow::text($row['account_id'] ?? null),
            TransactionRow::text($row['grouping_key'] ?? null),
            TransactionRow::text($row['display_name'] ?? null),
            new \DateTimeImmutable(TransactionRow::text($row['booked_on'] ?? null), new \DateTimeZone('UTC')),
            new AssetAmount(
                DecimalValue::fromString(TransactionRow::decimal($row['amount_value'] ?? null, $row['amount_scale'] ?? null)),
                AssetCode::fromString(TransactionRow::text($row['asset_code'] ?? null)),
            ),
        );
    }
}
