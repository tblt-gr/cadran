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
use App\Module\Foundation\Domain\UuidGenerator;

final readonly class CreateCategory
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private CategoryRepository $categories,
        private PresentCategory $presentCategory,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(CreateCategoryInput $input): CategoryView
    {
        $context = $this->caller->resolveContext();
        $type = CategoryInputParser::type($input->type);
        $axes = CategoryInputParser::axes($input->defaultAnalyticAxes);
        $parentId = CategoryInputParser::optionalIdentifier($input->parentId);
        $label = trim($input->label);

        return $this->transactionBoundary->transactional(function () use ($input, $context, $type, $axes, $parentId, $label): CategoryView {
            $parent = null;
            if (null !== $parentId) {
                $parent = $this->categories->findForUpdate($context->workspace, $parentId);
                if (null === $parent || null !== $parent->archivedAt || $parent->type !== $type) {
                    throw new InvalidCategoryInput('The category parent must be an active category of the same type in this workspace.');
                }
            }

            if ($this->categories->hasActiveSiblingLabel(
                $context->workspace,
                $type,
                $parentId,
                $label,
            )) {
                throw new CategoryConflict('An active sibling already uses this label.');
            }

            try {
                $now = new \DateTimeImmutable();
                $category = new Category(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    type: $type,
                    label: $label,
                    parentId: $parentId,
                    icon: $input->icon,
                    color: null === $input->color ? null : strtoupper($input->color),
                    defaultAnalyticAxes: $axes,
                    budgetIncluded: $input->budgetIncluded,
                    sortOrder: $input->sortOrder,
                    depth: null === $parent ? 1 : $parent->depth + 1,
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidCategory $exception) {
                throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
            }

            $this->categories->add($category);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: CategoryAuditEvents::CREATED,
                entityType: CategoryAuditEvents::ENTITY,
                entityId: $category->id,
                diff: AuditDiff::creation([
                    'type' => $category->type->value,
                    'hasParent' => null !== $category->parentId,
                    'budgetIncluded' => $category->budgetIncluded,
                ]),
            ));

            return ($this->presentCategory)($context->workspace, $category);
        });
    }
}
