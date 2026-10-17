<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\Plan\ImportPlan;

/** The outcome of one run: the plan, whether it was committed, and the refusal that rolled it back, if any. */
final readonly class ImportReport
{
    public function __construct(
        public ImportPlan $plan,
        public bool $committed,
        public ?ImportExecutionFailed $failure,
    ) {
    }

    public function isRejected(): bool
    {
        return $this->plan->hasRejections() || null !== $this->failure;
    }
}
