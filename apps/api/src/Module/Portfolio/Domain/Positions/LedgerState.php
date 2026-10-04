<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\CalculatedDecimal;

/** Mutable working state private to one pure replay invocation. */
final class LedgerState
{
    /** @var list<OpenLot> */
    public array $lots = [];
    public CalculatedDecimal $poolQuantity;
    public ?CalculatedDecimal $poolCost;
    public ?\DateTimeImmutable $first = null;
    public ?\DateTimeImmutable $last = null;

    /** @var array<string, ?CalculatedDecimal> operation identifier => average-pool cost removed by that disposal */
    public array $released = [];

    public function __construct()
    {
        $this->poolQuantity = CalculatedDecimal::zero();
        $this->poolCost = CalculatedDecimal::zero();
    }
}
