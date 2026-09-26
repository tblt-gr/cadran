<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain\Aggregation;

final class AggregationPopulationTooLarge extends \DomainException
{
    public static function of(int $count): self
    {
        return new self(sprintf('An aggregate takes at most %d months, %d given.', ReportAggregator::MAX_MONTHS, $count));
    }
}
