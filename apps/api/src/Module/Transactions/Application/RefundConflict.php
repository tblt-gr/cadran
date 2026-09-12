<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\DecimalValue;

final class RefundConflict extends \DomainException
{
    public function __construct(public readonly string $ruleCode, public readonly DecimalValue $remaining)
    {
        parent::__construct($ruleCode);
    }
}
