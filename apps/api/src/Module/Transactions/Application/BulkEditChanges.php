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
    ) {
    }

    public function editsOtherThanCategory(): bool
    {
        return null !== $this->label || null !== $this->paymentMethod || null !== $this->note || null !== $this->counterparty;
    }
}
