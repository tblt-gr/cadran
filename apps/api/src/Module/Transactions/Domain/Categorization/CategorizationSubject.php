<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;

/** The facts of a movement a rule may read — a stored transaction or one being created. */
final readonly class CategorizationSubject
{
    public function __construct(
        public string $accountId,
        public AssetAmount $amount,
        public TransactionNature $nature,
        public TransactionState $state,
        public \DateTimeImmutable $bookedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $mcc,
    ) {
    }

    public static function fromTransaction(Transaction $transaction): self
    {
        return new self(
            $transaction->accountId, $transaction->amount, $transaction->nature, $transaction->state,
            $transaction->bookedOn, $transaction->rawLabel, $transaction->counterparty, $transaction->mcc,
        );
    }

    /**
     * Rules fire on ordinary income and spending only: a transfer leg, a refund
     * and a voided or rejected movement are never categorised automatically.
     */
    public function isEligible(): bool
    {
        return in_array($this->nature, [TransactionNature::INCOME, TransactionNature::EXPENSE, TransactionNature::FEE, TransactionNature::ADJUSTMENT], true)
            && !in_array($this->state, [TransactionState::VOIDED, TransactionState::REJECTED], true);
    }

    /** Mirrors the stored generated column `lower(btrim(raw_label))`. */
    public function normalizedLabel(): string
    {
        return mb_strtolower(trim($this->rawLabel));
    }
}
