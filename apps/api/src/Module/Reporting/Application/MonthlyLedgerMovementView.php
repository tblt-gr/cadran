<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerMovementView
{
    public function __construct(
        public string $id,
        public ?string $transactionId,
        public ?string $transferId,
        public string $bookedOn,
        public string $label,
        public string $amount,
        public string $assetCode,
        public ?string $direction = null,
        public ?string $counterpartAccountId = null,
        public ?string $counterpartAccountLabel = null,
        /** A pending movement is listed for information only: it enters no sum, count or KPI. */
        public bool $pending = false,
        /** A planned row counted as if booked on its date: it is part of the totals, and still pending. */
        public bool $upcoming = false,
    ) {
    }
}
