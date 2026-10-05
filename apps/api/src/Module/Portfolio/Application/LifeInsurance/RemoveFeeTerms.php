<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;

/** A mistaken version is deleted and recreated: rows are never edited. */
final readonly class RemoveFeeTerms
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private FeeTermsRepository $feeTerms,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
    ) {
    }

    public function __invoke(string $id): void
    {
        $context = $this->caller->resolveContext();
        $this->transactions->transactional(function () use ($context, $id): void {
            $terms = $this->feeTerms->find($context->workspace, $id) ?? throw new LifeInsuranceNotFound('life_insurance_fee_terms_not_found');
            $contract = $this->contracts->find($context->workspace, $terms->contractId) ?? throw new LifeInsuranceNotFound('life_insurance_fee_terms_not_found');
            $this->gate->forLifeInsurance($context->workspace, $contract->accountId, true);
            $this->contracts->findForUpdate($context->workspace, $contract->id);
            if (!$this->feeTerms->delete($context->workspace, $terms->id)) {
                throw new LifeInsuranceNotFound('life_insurance_fee_terms_not_found');
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, LifeInsuranceAuditEvents::FEE_TERMS_REMOVED,
                LifeInsuranceAuditEvents::FEE_TERMS, $terms->id, LifeInsuranceAudit::removedFeeTerms($terms),
            ));
        });
    }
}
