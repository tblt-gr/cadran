<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\InvalidCategory;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retires a category without deleting it. A used category keeps its history and
 * its identifier for good; archiving only takes it out of the choices offered
 * for new classifications.
 */
final readonly class ArchiveCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryRepository $categories,
        private AssessCategoryImpact $assessImpact,
        private PresentCategory $presentCategory,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $expectedVersion): CategoryView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($id, $expectedVersion, $context): CategoryView {
            $impact = ($this->assessImpact)(
                $context->workspace,
                CategoryLifecycleOperation::ARCHIVE,
                $id,
                null,
                null,
                lock: true,
            );
            $current = $impact->source;
            if ($expectedVersion !== $current->version) {
                throw new CategoryConflict('The category was changed by another request.');
            }
            if (!$impact->isAllowed()) {
                throw new CategoryOperationRefused($impact->blockers);
            }

            try {
                $archived = $current->archive($this->clock->now());
            } catch (InvalidCategory $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->categories->update($archived, $current->version)) {
                throw new CategoryConflict('The category was changed by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::ARCHIVED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $archived->id,
                diff: AuditDiff::change(
                    CategoryAuditFingerprint::of($current),
                    CategoryAuditFingerprint::of($archived),
                ),
            ));

            return ($this->presentCategory)($context->workspace, $archived);
        });
    }
}
