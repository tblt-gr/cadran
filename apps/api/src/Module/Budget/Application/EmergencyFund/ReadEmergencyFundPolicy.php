<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicyRepository;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Application\CallerWorkspace;

final readonly class ReadEmergencyFundPolicy
{
    public function __construct(
        private CallerWorkspace $caller,
        private EmergencyFundPolicyRepository $policies,
        private ReadBudgetCategoryFacts $categories,
    ) {
    }

    public function __invoke(): EmergencyFundPolicyView
    {
        $workspace = $this->caller->resolve();
        $policy = $this->policies->find($workspace) ?? EmergencyFundPolicy::default($workspace);

        return EmergencyFundPolicyView::of($policy, [] === $policy->essentialCategoryIds ? [] : ($this->categories)($workspace));
    }
}
