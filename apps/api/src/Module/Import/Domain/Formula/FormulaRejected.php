<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Formula;

use App\Module\Import\Domain\ReasonCode;

/** The message never quotes the formula or the cell content: they can carry amounts. */
final class FormulaRejected extends \RuntimeException
{
    public function __construct(public readonly ReasonCode $reason)
    {
        parent::__construct(sprintf('Amount formula rejected: %s.', $reason->value));
    }
}
