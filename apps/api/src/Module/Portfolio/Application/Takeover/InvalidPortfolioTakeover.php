<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

final class InvalidPortfolioTakeover extends \DomainException
{
    /** @param non-empty-list<TakeoverError> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The takeover request breaks at least one rule.');
    }
}
