<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\CategoryReplacement;
use App\Module\Categories\Domain\CategoryReplacementRepository;
use App\Module\Categories\Domain\InvalidCategoryReplacement;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records that a category is replaced by another one from a date onwards.
 *
 * Unlike a merge, the source keeps its tree position and stays selectable for
 * the period before the date: what happened before the change happened under
 * the old category, and rewriting that would be exactly the silent historical
 * change this operation exists to avoid.
 */
final readonly class ReplaceCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryReplacementRepository $replacements,
        private AssessCategoryImpact $assessImpact,
        private PresentCategory $presentCategory,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, ?string $targetId, string $effectiveFrom, int $expectedVersion): CategoryView
    {
        $context = $this->caller->resolveContext();
        $targetId = CategoryInputParser::optionalIdentifier($targetId);
        $effectiveDate = CategoryInputParser::businessDay($effectiveFrom);

        return $this->transactionBoundary->transactional(function () use ($id, $targetId, $effectiveDate, $expectedVersion, $context): CategoryView {
            $impact = ($this->assessImpact)(
                $context->workspace,
                CategoryLifecycleOperation::REPLACE,
                $id,
                $targetId,
                $effectiveDate,
                lock: true,
            );
            $current = $impact->source;
            if ($expectedVersion !== $current->version) {
                throw new CategoryConflict('The category was changed by another request.');
            }
            if (!$impact->isAllowed() || null === $impact->target) {
                throw new CategoryOperationRefused($impact->blockers);
            }

            $target = $impact->target;
            try {
                $replacement = CategoryReplacement::from(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    sourceCategoryId: $current->id,
                    targetCategoryId: $target->id,
                    effectiveFrom: $effectiveDate,
                    createdAt: $this->clock->now(),
                );
            } catch (InvalidCategoryReplacement $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            $this->replacements->add($replacement);

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::REPLACED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $current->id,
                diff: AuditDiff::change(
                    [...CategoryAuditFingerprint::of($current), 'replacedBy' => null, 'effectiveFrom' => null],
                    [
                        ...CategoryAuditFingerprint::of($current),
                        'replacedBy' => $target->id,
                        'effectiveFrom' => $effectiveDate->format('Y-m-d'),
                    ],
                ),
            ));

            return ($this->presentCategory)($context->workspace, $current);
        });
    }
}
