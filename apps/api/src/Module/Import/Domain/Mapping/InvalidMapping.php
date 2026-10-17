<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/** The mapping file is unusable: a usage error (exit 2) carrying every problem found, not only the first. */
final class InvalidMapping extends \RuntimeException
{
    /**
     * @param non-empty-list<MappingProblem> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(sprintf(
            'The mapping file is invalid (%d problem%s): %s',
            count($problems),
            1 === count($problems) ? '' : 's',
            implode(', ', array_map(static fn (MappingProblem $problem): string => $problem->location.' '.$problem->code->value, $problems)),
        ));
    }
}
