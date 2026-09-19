<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\MonthlyAccountFact;

final readonly class MonthlyAccountView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $assetCode,
        public string $kind,
        public MonthlyAccountValueView $beginningValue,
        public MonthlyAccountValueView $endValue,
        public string $reconciliationStatus,
    ) {
    }

    public static function of(MonthlyAccountFact $fact): self
    {
        return new self(
            $fact->id,
            $fact->label,
            $fact->assetCode,
            $fact->kind,
            MonthlyAccountValueView::of($fact->beginning),
            MonthlyAccountValueView::of($fact->end),
            $fact->reconciliationStatus,
        );
    }
}
