<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Plan;

/**
 * One record the import will create, fully resolved against the workspace
 * (account and category identifiers, exact signed amounts, booking day) and
 * located by sheet and cell so a failure never has to quote a label.
 */
interface PlannedStep
{
    public function kind(): StepKind;

    /** The month of the sheet the step comes from, also for the opening records of the first month. */
    public function month(): int;

    public function sheet(): string;

    public function cell(): ?string;
}
