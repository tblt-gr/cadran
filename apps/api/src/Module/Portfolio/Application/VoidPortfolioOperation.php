<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class VoidPortfolioOperation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioOperationRepository $operations,
        private PortfolioAccountGate $gate,
        private PortfolioOperationPresenter $presenter,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $operationId, int $version, ?string $reason): OperationView
    {
        $context = $this->caller->resolveContext();

        $voided = $this->transactions->transactional(function () use ($context, $operationId, $version, $reason): PortfolioOperation {
            $found = $this->operations->find($context->workspace, $operationId) ?? throw new PortfolioOperationNotFound();
            ($this->assertPeriodOpen)($context->workspace, $found->details->tradeDate);
            $this->gate->forWrite($context->workspace, $found->accountId, true);
            $operation = $this->operations->findForUpdate($context->workspace, $operationId) ?? throw new PortfolioOperationNotFound();

            // A settled operation is refused before its version is compared: nothing is left to retry.
            $operation->assertActive();
            if ($operation->version !== $version) {
                throw new StalePortfolioOperationVersion();
            }
            $voided = $operation->void(OperationVoidKind::VOIDED, $reason, $this->clock->now(), $context->actorId);
            if (!$this->operations->markVoided($voided, $version)) {
                throw new StalePortfolioOperationVersion();
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, PortfolioOperationAuditEvents::VOIDED,
                PortfolioOperationAuditEvents::ENTITY, $voided->id, PortfolioOperationAudit::voided($voided),
            ));

            return $voided;
        });

        return $this->presenter->present($context->workspace, [$voided])[0];
    }
}
