<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\BudgetOverlapDetector;
use App\Module\Budget\Domain\BudgetPlanRepository;
use App\Module\Budget\Domain\BudgetPlanState;
use App\Module\Budget\Domain\BudgetScopeType;
use App\Module\Budget\Domain\BudgetTarget;
use App\Module\Budget\Domain\BudgetTargetRepository;
use App\Module\Budget\Domain\BudgetValueType;
use App\Module\Budget\Domain\InvalidBudgetTarget;
use App\Module\Categories\Application\ReadCategoryReference;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reference\Application\AssetCatalog;

final readonly class CreateBudgetTarget
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private BudgetPlanRepository $plans,
        private BudgetTargetRepository $targets,
        private ReadCategoryReference $categoryReference,
        private AssetCatalog $assets,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
    ) {
    }

    public function __invoke(CreateBudgetTargetInput $input): BudgetTargetView
    {
        $context = $this->caller->resolveContext();

        $scopeType = BudgetScopeType::tryFrom($input->scopeType);
        $valueType = BudgetValueType::tryFrom($input->valueType);
        if (null === $scopeType || null === $valueType) {
            throw new InvalidBudgetTargetInput('A budget target scope and value type must be recognised.');
        }

        return $this->transactionBoundary->transactional(function () use ($context, $scopeType, $valueType, $input): BudgetTargetView {
            $plan = $this->plans->findForUpdate($context->workspace, $input->planId);
            if (null === $plan) {
                throw new BudgetPlanNotFound('This budget plan does not exist in this workspace.');
            }
            if (BudgetPlanState::CLOSED === $plan->state) {
                throw new InvalidBudgetTargetInput('A closed budget plan cannot receive a new target.');
            }
            if ($this->targets->countByPlan($context->workspace, $plan->id) >= BudgetOverlapDetector::MAX_TARGETS) {
                throw new InvalidBudgetTargetInput(sprintf('A budget plan accepts at most %d targets.', BudgetOverlapDetector::MAX_TARGETS));
            }

            $this->assertScopeReference($context->workspace, $scopeType, $input->scopeId);

            try {
                $amount = null === $input->amount ? null : DecimalValue::fromString($input->amount);
                $ratio = null === $input->ratio ? null : DecimalValue::fromString($input->ratio);
                if (null !== $amount) {
                    $asset = $this->assets->findByCode($plan->assetCode);
                    if (null === $asset) {
                        throw new InvalidBudgetTargetInput('The budget plan asset is no longer available.');
                    }
                    $amount->assertScaleAtMost($asset->precision->storage);
                }
            } catch (InvalidBudgetTargetInput $exception) {
                throw $exception;
            } catch (MalformedDecimal|PrecisionExceeded $exception) {
                throw new InvalidBudgetTargetInput($exception->getMessage(), previous: $exception);
            }

            try {
                $now = new \DateTimeImmutable();
                $target = new BudgetTarget(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    planId: $plan->id,
                    scopeType: $scopeType,
                    scopeId: $input->scopeId,
                    valueType: $valueType,
                    amount: $amount,
                    ratio: $ratio,
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidBudgetTarget $exception) {
                throw new InvalidBudgetTargetInput($exception->getMessage(), previous: $exception);
            }

            $this->targets->add($target);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: BudgetAuditEvents::TARGET_CREATED,
                entityType: BudgetAuditEvents::TARGET_ENTITY,
                entityId: $target->id,
                diff: AuditDiff::creation([
                    'planId' => $target->planId,
                    'scopeType' => $target->scopeType->value,
                    'valueType' => $target->valueType->value,
                ]),
            ));

            return (new PresentBudgetTarget())($target);
        });
    }

    private function assertScopeReference(\App\Module\Foundation\Domain\WorkspaceScope $workspace, BudgetScopeType $scopeType, string $scopeId): void
    {
        if (BudgetScopeType::AXIS === $scopeType) {
            if (null === AnalyticAxis::tryFrom($scopeId)) {
                throw new InvalidBudgetTargetReference('A budget target axis must be a recognised analytic axis.');
            }

            return;
        }

        $fact = ($this->categoryReference)($workspace, $scopeId);
        if (null === $fact) {
            throw new InvalidBudgetTargetReference('A budget target category must exist in this workspace.');
        }
        if ($fact->archived) {
            throw new InvalidBudgetTargetReference('A budget target cannot scope to an archived category.');
        }
    }
}
