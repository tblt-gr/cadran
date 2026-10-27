<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Reporting\Domain\Aggregation\ColumnKind;

/** One column of the annual report. A column whose reference no longer resolves stays, with every cell null. */
final readonly class AnnualColumn
{
    public function __construct(
        public string $id,
        public string $label,
        public ColumnKind $kind,
        public bool $known,
        /** Institution of an account column, shown under its label; null for every other column. */
        public ?string $institution = null,
        /** Label of the immediate parent of a sub-group column, shown beside its name; null for a root group and every other column. */
        public ?string $parentLabel = null,
    ) {
    }
}
