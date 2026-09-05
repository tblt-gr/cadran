<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Foundation\Application\CallerWorkspace;

/**
 * Answers what a lifecycle operation would change, without changing anything.
 *
 * The read is deliberately unlocked: a preview that held row locks would let a
 * reader block writers for as long as a confirmation screen stays open. The
 * operation itself repeats the assessment under lock before it writes.
 */
final readonly class PreviewCategoryImpact
{
    public function __construct(
        private CallerWorkspace $caller,
        private AssessCategoryImpact $assessImpact,
    ) {
    }

    public function __invoke(
        string $id,
        string $operation,
        ?string $targetId,
        ?string $effectiveFrom,
    ): CategoryImpactView {
        $lifecycle = CategoryInputParser::lifecycleOperation($operation);
        $target = CategoryInputParser::optionalIdentifier($targetId);
        if (CategoryLifecycleOperation::ARCHIVE === $lifecycle && null !== $target) {
            throw new InvalidCategoryInput('Archiving a category takes no target.');
        }

        $effectiveDate = null;
        if (CategoryLifecycleOperation::REPLACE === $lifecycle) {
            if (null === $effectiveFrom) {
                throw new InvalidCategoryInput('A replacement preview needs the date it would take effect on.');
            }

            $effectiveDate = CategoryInputParser::businessDay($effectiveFrom);
        } elseif (null !== $effectiveFrom) {
            throw new InvalidCategoryInput('Only a replacement takes an effective date.');
        }

        return CategoryImpactView::of(($this->assessImpact)(
            $this->caller->resolve(),
            $lifecycle,
            $id,
            $target,
            $effectiveDate,
            lock: false,
        ));
    }
}
