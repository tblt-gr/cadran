<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class InvalidRefundRule extends \DomainException
{
    public function __construct(public readonly string $ruleCode)
    {
        parent::__construct($ruleCode);
    }
}
