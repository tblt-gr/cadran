<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Portfolio\Application\PortfolioOperationConflict;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

/** Called only under the account lock, in the same transaction as the history mutation. */
final readonly class ReconcileContractOperations
{
    public function __construct(private LifeInsuranceContractRepository $contracts, private PortfolioOperationRepository $operations, private ValidateContractHistory $history, private RecordAuditEvent $audit)
    {
    }

    public function __invoke(WorkspaceContext $context, string $accountId, \DateTimeImmutable $at): void
    {
        $found = $this->contracts->findByAccount($context->workspace, $accountId);
        if (null === $found) {
            return;
        }
        $contract = $this->contracts->findForUpdate($context->workspace, $found->id) ?? throw new \LogicException('Contract disappeared under the account lock.');
        $operations = $this->operations->activeForAccount($context->workspace, $accountId);
        $closedOn = $this->history->validate($context->workspace, $operations)['closedOn'];
        if ($closedOn?->format('Y-m-d') === $contract->closedOn?->format('Y-m-d')) {
            return;
        }
        $updated = $contract->withClosure($closedOn, $at);
        if (!$this->contracts->update($updated, $contract->version)) {
            throw new PortfolioOperationConflict(PortfolioOperationConflict::CONCURRENT_CHANGE);
        }
        ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId,
            null === $closedOn ? 'life_insurance_contract.reopened' : 'life_insurance_contract.closed',
            'life_insurance_contract', $contract->id, AuditDiff::change(['status' => $contract->status->value, 'closedOn' => $contract->closedOn?->format('Y-m-d')], ['status' => $updated->status->value, 'closedOn' => $closedOn?->format('Y-m-d')])));
    }
}
