<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;

final readonly class RemoveEuroFundRate
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private EuroFundRateRepository $rates,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
    ) {
    }

    public function __invoke(string $id): void
    {
        $context = $this->caller->resolveContext();
        $this->transactions->transactional(function () use ($context, $id): void {
            $rate = $this->rates->find($context->workspace, $id) ?? throw new LifeInsuranceNotFound('euro_fund_rate_not_found');
            $contract = $this->contracts->find($context->workspace, $rate->contractId) ?? throw new LifeInsuranceNotFound('euro_fund_rate_not_found');
            $this->gate->forLifeInsurance($context->workspace, $contract->accountId, true);
            $this->contracts->findForUpdate($context->workspace, $contract->id);
            if (!$this->rates->delete($context->workspace, $rate->id)) {
                throw new LifeInsuranceNotFound('euro_fund_rate_not_found');
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, LifeInsuranceAuditEvents::RATE_REMOVED,
                LifeInsuranceAuditEvents::RATE, $rate->id, LifeInsuranceAudit::removedRate($rate),
            ));
        });
    }
}
