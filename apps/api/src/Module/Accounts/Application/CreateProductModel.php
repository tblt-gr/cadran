<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a product model the workspace describes itself.
 *
 * Nothing here reads or writes the system catalogue: a workspace models the
 * product its own institution sells, and the two stores stay apart so a
 * catalogue revision can never overwrite a figure the holder entered, nor a
 * request reach a system product.
 */
final readonly class CreateProductModel
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ProductModelRepository $models,
        private SubmittedModelRule $submittedRule,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateProductModelInput $input): ProductModelView
    {
        $context = $this->caller->resolveContext();

        if (count($input->rules) > ProductModelInputParser::MAX_RULES_PER_REQUEST) {
            throw new InvalidProductModelInput(sprintf('A request records at most %d rule periods.', ProductModelInputParser::MAX_RULES_PER_REQUEST));
        }

        $family = ProductModelInputParser::family($input->family);
        $wrapperKind = ProductModelInputParser::wrapperKind($input->wrapperKind);
        $yieldKind = ProductModelInputParser::yieldKind($input->yieldKind);
        $valuationMode = ProductModelInputParser::valuationMode($input->valuationMode);
        $capabilities = ProductModelInputParser::capabilities($input->capabilities);

        $rules = [];
        foreach ($input->rules as $rule) {
            $rules[] = $this->submittedRule->toRule($rule);
        }

        $name = trim($input->name);

        return $this->transactionBoundary->transactional(function () use (
            $context,
            $name,
            $family,
            $wrapperKind,
            $yieldKind,
            $input,
            $valuationMode,
            $capabilities,
            $rules,
        ): ProductModelView {
            if ($this->models->hasActiveName($context->workspace, $name)) {
                throw new ProductModelConflict('An active model already uses this name.');
            }

            $now = $this->clock->now();

            try {
                $model = new ProductModel(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    name: $name,
                    family: $family,
                    wrapperKind: $wrapperKind,
                    yieldKind: $yieldKind,
                    defaultGroupCode: $input->defaultGroupCode,
                    valuationMode: $valuationMode,
                    capabilities: $capabilities,
                    provenance: ModelProvenance::declared(),
                    schedule: new ModelRuleSchedule($rules),
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidProductModel $exception) {
                throw new InvalidProductModelInput($exception->getMessage(), previous: $exception);
            }

            $this->models->add($model);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: ProductModelAuditEvents::CREATED,
                entityType: ProductModelAuditEvents::ENTITY,
                entityId: $model->id,
                diff: AuditDiff::creation(ProductModelAuditFingerprint::of($model)),
            ));

            return ProductModelView::fromModel($model);
        });
    }
}
