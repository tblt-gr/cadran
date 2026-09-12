<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\RefundRepository;
use App\Module\Transactions\Domain\TransactionRefund;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(RefundRepository::class)]
final readonly class DbalRefundRepository implements RefundRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(TransactionRefund $refund): void
    {
        $this->connection->insert('transaction_refunds', [
            'id' => $refund->id,
            'workspace_id' => $refund->workspace->id,
            'refund_transaction_id' => $refund->refundTransactionId,
            'original_transaction_id' => $refund->originalTransactionId,
            'created_at' => $refund->createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function findByRefundTransactionId(WorkspaceScope $workspace, string $refundTransactionId): ?TransactionRefund
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, refund_transaction_id, original_transaction_id, created_at FROM transaction_refunds '
            .'WHERE workspace_id = :workspace_id AND refund_transaction_id = :refund_transaction_id',
            ['workspace_id' => $workspace->id, 'refund_transaction_id' => $refundTransactionId],
        );

        return false === $row ? null : self::hydrate($workspace, $row);
    }

    public function findByOriginalTransactionId(WorkspaceScope $workspace, string $originalTransactionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, refund_transaction_id, original_transaction_id, created_at FROM transaction_refunds '
            .'WHERE workspace_id = :workspace_id AND original_transaction_id = :original_transaction_id ORDER BY created_at, id',
            ['workspace_id' => $workspace->id, 'original_transaction_id' => $originalTransactionId],
        );

        return array_map(fn (array $row): TransactionRefund => self::hydrate($workspace, $row), $rows);
    }

    public function hasLiveRefund(WorkspaceScope $workspace, string $originalTransactionId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM transaction_refunds r JOIN transaction_transactions t '
            .'ON t.workspace_id = r.workspace_id AND t.id = r.refund_transaction_id '
            ."WHERE r.workspace_id = :workspace_id AND t.workspace_id = :workspace_id AND r.original_transaction_id = :original_transaction_id AND t.state <> 'VOIDED')",
            ['workspace_id' => $workspace->id, 'original_transaction_id' => $originalTransactionId],
        );
    }

    public function refundedAmount(WorkspaceScope $workspace, string $originalTransactionId): DecimalValue
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(sum(t.amount_value), 0) AS value, COALESCE(max(t.amount_scale), 0) AS scale FROM transaction_refunds r JOIN transaction_transactions t '
            .'ON t.workspace_id = r.workspace_id AND t.id = r.refund_transaction_id '
            ."WHERE r.workspace_id = :workspace_id AND t.workspace_id = :workspace_id AND r.original_transaction_id = :original_transaction_id AND t.state <> 'VOIDED'",
            ['workspace_id' => $workspace->id, 'original_transaction_id' => $originalTransactionId],
        );

        if (false === $row) {
            throw new \UnexpectedValueException('Refund total query unexpectedly returned no row.');
        }
        $value = DecimalValue::fromString(self::scalar($row['value'] ?? null));

        return ExactDecimal::round($value, (int) self::scalar($row['scale'] ?? null), RoundingMode::DOWN);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(WorkspaceScope $workspace, array $row): TransactionRefund
    {
        return new TransactionRefund(
            self::scalar($row['id'] ?? null), $workspace, self::scalar($row['refund_transaction_id'] ?? null),
            self::scalar($row['original_transaction_id'] ?? null), new \DateTimeImmutable(self::scalar($row['created_at'] ?? null)),
        );
    }

    private static function scalar(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }
}
