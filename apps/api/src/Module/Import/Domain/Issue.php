<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/** A reason code located by sheet and cell coordinate, never by content. */
final readonly class Issue
{
    public function __construct(
        public ReasonCode $code,
        public ?string $sheet,
        public ?string $cell,
        /** Overrides the default of the code: a month not ended is excluded as a sheet and rejected as a booking date. */
        public ?bool $rejection = null,
    ) {
    }

    public function isRejection(): bool
    {
        return $this->rejection ?? $this->code->isRejection();
    }
}
