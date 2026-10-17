<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

/**
 * A problem of the mapping file, located by section and 1-based position of the
 * entry (`income[3].category`), never by the label itself: labels are private.
 */
final readonly class MappingProblem
{
    public function __construct(
        public string $location,
        public MappingProblemCode $code,
    ) {
    }
}
