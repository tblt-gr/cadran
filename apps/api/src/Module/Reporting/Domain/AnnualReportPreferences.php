<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\Aggregation\IncompleteMonths;

/**
 * The columns and the incomplete-month setting the annual report of one workspace shows. It picks
 * what is displayed and never changes a formula or a policy. Version 0 means nothing is stored yet.
 */
final readonly class AnnualReportPreferences
{
    public const int UNSAVED_VERSION = 0;
    public const int MAX_COLUMNS = 40;

    /** @param list<string> $columns */
    public function __construct(
        public WorkspaceScope $workspace,
        public array $columns,
        public IncompleteMonths $incompleteMonths,
        public int $version,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        if ($version < self::UNSAVED_VERSION) {
            throw new \InvalidArgumentException('An annual report preference version is never negative.');
        }
        if (count($columns) > self::MAX_COLUMNS || count($columns) !== count(array_unique($columns))) {
            throw new \InvalidArgumentException('An annual report selection holds at most 40 distinct columns.');
        }
    }

    /** @param list<string> $columns */
    public function saved(array $columns, IncompleteMonths $incompleteMonths, \DateTimeImmutable $at): self
    {
        return new self($this->workspace, $columns, $incompleteMonths, $this->version + 1, $at);
    }
}
