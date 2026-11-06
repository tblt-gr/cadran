<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** What a bulk edit applies. A null property is a field left untouched. */
final readonly class BulkEditChanges
{
    public function __construct(
        public ?BulkLabelChange $label = null,
        public ?BulkFieldChange $paymentMethod = null,
        public ?BulkFieldChange $note = null,
        public ?BulkFieldChange $counterparty = null,
        public ?string $categoryId = null,
        public ?string $accountId = null,
        public ?\DateTimeImmutable $bookedOn = null,
    ) {
    }

    /** Whether any money-moving field is requested: those need a preview first. */
    public function moves(): bool
    {
        return null !== $this->accountId || null !== $this->bookedOn;
    }

    public function editsOtherThanCategory(): bool
    {
        return null !== $this->label || null !== $this->paymentMethod || null !== $this->note || null !== $this->counterparty || $this->moves();
    }

    /** A stable text form of the whole request, for the preview token to bind to. */
    public function canonical(): string
    {
        return json_encode([
            'label' => $this->label?->canonical(),
            'paymentMethod' => $this->paymentMethod?->value,
            'hasPaymentMethod' => null !== $this->paymentMethod,
            'note' => $this->note?->value,
            'hasNote' => null !== $this->note,
            'counterparty' => $this->counterparty?->value,
            'hasCounterparty' => null !== $this->counterparty,
            'categoryId' => $this->categoryId,
            'accountId' => $this->accountId,
            'bookedOn' => $this->bookedOn?->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR);
    }
}
