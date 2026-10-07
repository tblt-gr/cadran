<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationType;

final readonly class SwitchFeeTerms
{
    public function __construct(private LifeInsuranceContractRepository $contracts, private FeeTermsRepository $terms)
    {
    }

    public function forOperation(WorkspaceScope $workspace, string $accountId, OperationDetails $details): ?FeeTerms
    {
        if (OperationType::SWITCH !== $details->type) {
            return null;
        }
        $contract = $this->contracts->findByAccount($workspace, $accountId);

        return null === $contract ? null : FeeTerms::applicableOn($this->terms->listForContract($workspace, $contract->id), $details->tradeDate);
    }
}
