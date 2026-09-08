<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;

final readonly class TransactionDraft
{
    public function __construct(
        public AssetAmount $amount,
        public TransactionNature $nature,
        public TransactionState $state,
        public \DateTimeImmutable $bookedOn,
        public ?\DateTimeImmutable $valueOn,
        public ?\DateTimeImmutable $authorizedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $note,
        public ?PaymentMethod $paymentMethod,
        public ?string $mcc,
        public ?string $maskedCard,
        public ?string $bankReference,
    ) {
    }
}
