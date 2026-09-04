<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ProductModelIsArchived;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a dated period on a model.
 *
 * This is the only way a model changes, and it only adds. A period already
 * recorded is never edited or removed; an open-ended one the new period
 * supersedes is closed the day before it starts, which states when it stopped
 * applying instead of pretending it never did. Reading a statement from last
 * year must still resolve against last year's figures.
 */
final readonly class AddProductModelRule
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ProductModelRepository $models,
        private SubmittedModelRule $submittedRule,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, ModelRuleInput $input, int $expectedVersion): ProductModelView
    {
        $context = $this->caller->resolveContext();
        $rule = $this->submittedRule->toRule($input);

        return $this->transactionBoundary->transactional(function () use ($id, $rule, $expectedVersion, $context): ProductModelView {
            $current = $this->models->findForUpdate($context->workspace, $id);
            if (null === $current) {
                throw new ProductModelNotFound('No model carries this identifier in this workspace.');
            }
            if ($expectedVersion !== $current->version) {
                throw new StaleProductModelVersion('The model was changed by another request.');
            }

            try {
                $revised = $current->withRule($rule, $this->clock->now());
            } catch (ProductModelIsArchived $exception) {
                throw new ProductModelArchived($exception->getMessage(), previous: $exception);
            } catch (InvalidProductModel $exception) {
                throw new InvalidProductModelInput($exception->getMessage(), previous: $exception);
            }

            if (!$this->models->update($revised, $current->version)) {
                throw new StaleProductModelVersion('The model was changed by another request.');
            }

            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: ProductModelAuditEvents::RULE_RECORDED,
                entityType: ProductModelAuditEvents::ENTITY,
                entityId: $revised->id,
                diff: AuditDiff::change(
                    ProductModelAuditFingerprint::of($current),
                    ProductModelAuditFingerprint::of($revised),
                ),
            ));

            return ProductModelView::fromModel($revised);
        });
    }
}
