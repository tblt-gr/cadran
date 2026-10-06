<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Replaces an operation: the original is kept as REPLACED and the replacement
 * is recorded in the same transaction, so a failure leaves the original active.
 */
final readonly class CorrectPortfolioOperation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioOperationRepository $operations,
        private PortfolioAccountGate $gate,
        private OperationResolver $resolver,
        private LifeInsurance\ReconcileContractOperations $contractOperations,
        private PortfolioOperationRecorder $recorder,
        private PortfolioOperationPresenter $presenter,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $operationId, int $version, ?string $reason, OperationInput $input): CorrectionResult
    {
        $context = $this->caller->resolveContext();
        $newTradeDate = OperationResolver::parseTradeDate($input->tradeDate);

        /** @var array{PortfolioOperation, string} $committed */
        $committed = $this->transactions->transactional(function () use ($context, $operationId, $version, $reason, $input, $newTradeDate): array {
            $found = $this->operations->find($context->workspace, $operationId) ?? throw new PortfolioOperationNotFound();
            // Both the day the operation leaves and the day it enters must be open.
            ($this->assertPeriodOpen)($context->workspace, $found->details->tradeDate, $newTradeDate);
            $account = $this->gate->forWrite($context->workspace, $found->accountId, true);
            $original = $this->operations->findForUpdate($context->workspace, $operationId) ?? throw new PortfolioOperationNotFound();
            $now = $this->clock->now();

            $original->assertActive();
            if ($original->version !== $version) {
                throw new StalePortfolioOperationVersion();
            }

            $replaced = $original->void(OperationVoidKind::REPLACED, $reason, $now, $context->actorId);
            if (!$this->operations->markVoided($replaced, $version)) {
                throw new StalePortfolioOperationVersion();
            }
            if (OperationType::FULL_SURRENDER === $original->details->type) {
                ($this->contractOperations)($context, $account->id, $now);
            }
            $resolved = $this->resolver->resolve($account, $input, $this->calendar->today(), true, $original->id);
            $replacement = $this->recorder->record($context, $account, $resolved, $now, $original->id,
                $original->details->tradeDate == $newTradeDate ? $original->sequence : null,
            );
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, PortfolioOperationAuditEvents::REPLACED,
                PortfolioOperationAuditEvents::ENTITY, $replaced->id, PortfolioOperationAudit::replaced($replaced, $replacement->id),
            ));

            return [$replacement, $original->details->tradeDate->format('Y-m-d')];
        });
        [$replacement, $originalTradeDate] = $committed;

        return new CorrectionResult($this->presenter->present($context->workspace, [$replacement])[0], $originalTradeDate);
    }
}
