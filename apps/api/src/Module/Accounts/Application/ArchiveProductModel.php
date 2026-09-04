<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModelIsArchived;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Takes a model out of the working set without deleting it.
 *
 * Archiving stops new use: the model leaves the listings a picker reads and
 * accepts no further period. What it already said stays readable by
 * identifier, so anything created from it keeps a model to point at.
 */
final readonly class ArchiveProductModel
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ProductModelRepository $models,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $expectedVersion): ProductModelView
    {
        $context = $this->caller->resolveContext();

        return $this->transactionBoundary->transactional(function () use ($id, $expectedVersion, $context): ProductModelView {
            $current = $this->models->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new ProductModelNotFound('No model carries this identifier in this workspace.');
            }
            if ($expectedVersion !== $current->version) {
                throw new StaleProductModelVersion('The model was changed by another request.');
            }

            try {
                $archived = $current->archive($this->clock->now());
            } catch (ProductModelIsArchived $exception) {
                throw new ProductModelArchived($exception->getMessage(), previous: $exception);
            }

            if (!$this->models->update($archived, $current->version)) {
                throw new StaleProductModelVersion('The model was changed by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: ProductModelAuditEvents::ARCHIVED,
                entityType: ProductModelAuditEvents::ENTITY,
                entityId: $archived->id,
                diff: AuditDiff::change(
                    ProductModelAuditFingerprint::of($current),
                    ProductModelAuditFingerprint::of($archived),
                ),
            ));

            return ProductModelView::fromModel($archived);
        });
    }
}
