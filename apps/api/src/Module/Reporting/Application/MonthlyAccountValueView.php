<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\MonthlyAccountValuationFact;

final readonly class MonthlyAccountValueView
{
    public function __construct(
        public ?string $value,
        public ?string $assetCode,
        public string $quality,
        public ?int $ageDays,
        public ?string $valuedOn,
    ) {
    }

    public static function of(MonthlyAccountValuationFact $fact): self
    {
        return new self(
            $fact->amount?->value->toString(),
            $fact->amount?->asset->toString(),
            $fact->quality,
            $fact->ageDays,
            $fact->valuedOn,
        );
    }
}
