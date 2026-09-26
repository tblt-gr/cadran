<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The metric policy of a report year: one version, or several when the year spans a change. */
final readonly class AnnualPolicyView
{
    public const string SINGLE = 'SINGLE';
    public const string MIXED = 'MIXED';

    /** @param list<int> $versions */
    public function __construct(
        public string $state,
        public ?int $version,
        public ?string $label,
        public array $versions,
    ) {
    }
}
