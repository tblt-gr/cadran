<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The indivisible link between a transfer's two legs, kept as its own row so
 * creation, edition and voiding always touch both {@see Transaction} legs
 * together. It carries no amount of its own: each leg keeps its own exact
 * figure, and this aggregate only ever names which two (or three, with a fee)
 * transactions form one transfer.
 */
final readonly class Transfer
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $sourceTransactionId,
        public string $targetTransactionId,
        public ?string $feeTransactionId,
        public ?DecimalValue $exchangeRate,
        public int $version,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $voidedAt,
    ) {
        self::identifier($id, 'transfer');
        self::identifier($sourceTransactionId, 'source transaction');
        self::identifier($targetTransactionId, 'target transaction');
        if ($sourceTransactionId === $targetTransactionId) {
            throw new InvalidTransfer('A transfer connects two different transactions.');
        }
        if (null !== $feeTransactionId) {
            self::identifier($feeTransactionId, 'fee transaction');
            if ($feeTransactionId === $sourceTransactionId || $feeTransactionId === $targetTransactionId) {
                throw new InvalidTransfer('A transfer fee cannot repeat a leg.');
            }
        }
        if (null !== $exchangeRate && $exchangeRate->compareTo(DecimalValue::zero()) <= 0) {
            throw new InvalidTransfer('A transfer exchange rate must be positive.');
        }
        if ($version < 1 || $updatedAt < $createdAt) {
            throw new InvalidTransfer('A transfer version and timestamps must be ordered.');
        }
    }

    public function edit(?string $feeTransactionId, ?DecimalValue $exchangeRate, \DateTimeImmutable $updatedAt): self
    {
        if (null !== $this->voidedAt) {
            throw new InvalidTransfer('A voided transfer cannot be edited.');
        }

        return new self(
            $this->id, $this->workspace, $this->sourceTransactionId, $this->targetTransactionId,
            $feeTransactionId, $exchangeRate, $this->version + 1, $this->createdAt, $updatedAt, null,
        );
    }

    public function void(\DateTimeImmutable $voidedAt): self
    {
        if (null !== $this->voidedAt) {
            throw new InvalidTransfer('A transfer already voided cannot be voided again.');
        }

        return new self(
            $this->id, $this->workspace, $this->sourceTransactionId, $this->targetTransactionId,
            $this->feeTransactionId, $this->exchangeRate, $this->version + 1, $this->createdAt, $voidedAt, $voidedAt,
        );
    }

    /**
     * A same-asset transfer carries no rate: the two legs must net to exactly
     * zero, with no rounding anywhere. A cross-asset pair is exempt here —
     * {@see deriveExchangeRate()} is what relates them, and it never feeds
     * back into either amount.
     */
    public static function assertOpposition(AssetAmount $source, AssetAmount $target): void
    {
        if ($source->asset->toString() !== $target->asset->toString()) {
            return;
        }

        if (!ExactDecimal::isZero(ExactDecimal::add($source->value, $target->value))) {
            throw new InvalidTransfer('A same-asset transfer must net to exactly zero.');
        }
    }

    /**
     * The only rounding boundary in a cross-asset transfer: the quotient of
     * the two exact magnitudes, HALF_UP at scale 24. The result documents the
     * operation and is stored on both legs — it is never used to recompute
     * either amount.
     */
    public static function deriveExchangeRate(AssetAmount $source, AssetAmount $target): DecimalValue
    {
        return ExactDecimal::divide(ExactDecimal::absolute($target->value), ExactDecimal::absolute($source->value));
    }

    private static function identifier(string $id, string $field): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidTransfer(sprintf('A %s identifier must be a canonical UUID.', $field));
        }
    }
}
