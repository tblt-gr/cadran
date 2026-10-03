<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\WithdrawCalculatedAccountValuation;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use Symfony\Component\Clock\ClockInterface;

/** Supersedes a valuation without replacement and retires the figure it published to net worth. */
final readonly class WithdrawPortfolioValuation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ValuationRepository $valuations,
        private WithdrawCalculatedAccountValuation $withdrawPublished,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private PortfolioValuationPresenter $presenter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $valuationId, int $version): ValuationView
    {
        $context = $this->caller->resolveContext();

        $withdrawn = $this->transactions->transactional(function () use ($context, $valuationId, $version): Valuation {
            $found = $this->valuations->find($context->workspace, $valuationId) ?? throw new ValuationNotFound();
            ($this->assertPeriodOpen)($context->workspace, $found->valuedOn);
            $this->gate->forWrite($context->workspace, $found->accountId, true);
            $valuation = $this->valuations->findForUpdate($context->workspace, $valuationId) ?? throw new ValuationNotFound();
            if (!$valuation->isActive() || $valuation->version !== $version) {
                throw new ValuationConflict(ValuationConflict::STALE_VERSION);
            }
            $withdrawn = $valuation->supersededAt($this->clock->now(), true);
            if (!$this->valuations->markSuperseded($withdrawn, $version)) {
                throw new ValuationConflict(ValuationConflict::STALE_VERSION);
            }
            ($this->withdrawPublished)($context->workspace, $valuation->accountId, $valuation->valuedOn);
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, PortfolioValuationAuditEvents::WITHDRAWN,
                PortfolioValuationAuditEvents::ENTITY, $withdrawn->id, PortfolioValuationAudit::withdrawn($withdrawn, []),
            ));

            return $withdrawn;
        });

        return $this->presenter->present($context->workspace, [$withdrawn])[0];
    }
}
