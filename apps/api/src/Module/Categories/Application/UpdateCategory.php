<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Categories\Domain\InvalidCategory;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;

final readonly class UpdateCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryRepository $categories,
        private PresentCategory $presentCategory,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(string $id, UpdateCategoryInput $input): CategoryView
    {
        $context = $this->caller->resolveContext();
        $type = CategoryInputParser::type($input->type);
        $label = trim($input->label);

        return $this->transactionBoundary->transactional(function () use ($id, $input, $context, $type, $label): CategoryView {
            $current = $this->categories->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new CategoryNotFound();
            }
            if ($input->version !== $current->version) {
                throw new CategoryConflict('The category was changed by another request.');
            }

            // An archived category is read-only: report that before a sibling-label check that
            // would otherwise blame a label freed by the archive itself.
            if (null !== $current->archivedAt) {
                throw new InvalidCategoryInput('An archived category is read-only.');
            }

            $hasChildren = $this->categories->hasChildren($context->workspace, $current->id);
            if ($type !== $current->type && (null !== $current->parentId || $hasChildren)) {
                throw new InvalidCategoryInput('A category attached to the tree cannot change type without an impact-aware move.');
            }
            if ($this->categories->hasActiveSiblingLabel(
                $context->workspace,
                $type,
                $current->parentId,
                $label,
                [$current->id],
            )) {
                throw new CategoryConflict('An active sibling already uses this label.');
            }

            try {
                $updated = $current->reconfigure(
                    type: $type,
                    label: $label,
                    icon: $input->icon,
                    color: null === $input->color ? null : strtoupper($input->color),
                    defaultAnalyticAxes: CategoryInputParser::axes($input->defaultAnalyticAxes),
                    budgetIncluded: $input->budgetIncluded,
                    sortOrder: $input->sortOrder,
                    updatedAt: new \DateTimeImmutable(),
                );
            } catch (InvalidCategory $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->categories->update($updated, $current->version)) {
                throw new CategoryConflict('The category was changed by another request.');
            }
            $beforeAudit = [
                'type' => $current->type->value,
                'version' => $current->version,
                ...self::changeIndicators($current, $updated, false),
            ];
            $afterAudit = [
                'type' => $updated->type->value,
                'version' => $updated->version,
                ...self::changeIndicators($current, $updated, true),
            ];
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::UPDATED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $updated->id,
                diff: AuditDiff::change($beforeAudit, $afterAudit),
            ));

            return ($this->presentCategory)($context->workspace, $updated);
        });
    }

    /** @return array<string, bool> */
    private static function changeIndicators(Category $before, Category $after, bool $changedSide): array
    {
        $indicator = static fn (bool $changed): bool => $changedSide && $changed;

        return [
            'displayNameChanged' => $indicator($before->label !== $after->label),
            'iconChanged' => $indicator($before->icon !== $after->icon),
            'colorChanged' => $indicator($before->color !== $after->color),
            'axesChanged' => $indicator($before->defaultAnalyticAxes !== $after->defaultAnalyticAxes),
            'budgetInclusionChanged' => $indicator($before->budgetIncluded !== $after->budgetIncluded),
            'orderChanged' => $indicator($before->sortOrder !== $after->sortOrder),
            'typeChanged' => $indicator($before->type !== $after->type),
        ];
    }
}
