<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class CorrectionResult
{
    public function __construct(public OperationView $replacement, public string $originalTradeDate)
    {
    }
}
