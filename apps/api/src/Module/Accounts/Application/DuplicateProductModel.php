<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\InvalidDeclaredRule;
use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Copies one model of the workspace into a new one.
 *
 * The copy takes the description and the dated periods with their effective
 * dates untouched. It takes nothing else, and there is nothing else to take:
 * no balance, no transaction, no valuation and no external identifier has ever
 * belonged to a model, which is what makes duplication safe by construction
 * rather than by a list of fields to skip.
 *
 * An archived model is refused as a source. Archiving is what stops new use,
 * and starting a new model from a retired one is new use.
 */
final readonly class DuplicateProductModel
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ProductModelRepository $models,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, string $name): ProductModelView
    {
        $context = $this->caller->resolveContext();
        $copyName = trim($name);

        return $this->transactionBoundary->transactional(function () use ($id, $copyName, $context): ProductModelView {
            // The copy does not write the source, but it must still lock it:
            // otherwise an archive that commits after this read would let a
            // retired model start a new one.
            $source = $this->models->findForUpdate($context->workspace, $id);
            if (null === $source) {
                throw new ProductModelNotFound('No model carries this identifier in this workspace.');
            }

            if ($source->isArchived()) {
                throw new ProductModelArchived('An archived model cannot start a new one.');
            }

            if ($this->models->hasActiveName($context->workspace, $copyName)) {
                throw new ProductModelConflict('An active model already uses this name.');
            }

            $ruleIds = array_map(
                fn (): string => $this->uuidGenerator->generate(),
                $source->schedule->rules,
            );

            try {
                $copy = $source->duplicateAs(
                    $this->uuidGenerator->generate(),
                    $copyName,
                    $ruleIds,
                    $this->clock->now(),
                );
            } catch (InvalidProductModel|InvalidDeclaredRule $exception) {
                throw new InvalidProductModelInput($exception->getMessage(), previous: $exception);
            }

            $this->models->add($copy);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: ProductModelAuditEvents::DUPLICATED,
                entityType: ProductModelAuditEvents::ENTITY,
                entityId: $copy->id,
                diff: AuditDiff::creation(ProductModelAuditFingerprint::of($copy)),
            ));

            return ProductModelView::fromModel($copy);
        });
    }
}
