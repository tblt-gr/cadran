<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\EmergencyFundPolicyRepository;
use App\Module\Budget\Domain\EmergencyFund\EssentialMode;
use App\Module\Budget\Domain\EmergencyFund\InvalidEmergencyFundPolicy;
use App\Module\Budget\Domain\EmergencyFund\ReferenceMode;
use App\Module\Categories\Application\BudgetCategoryFact;
use App\Module\Categories\Application\ReadBudgetCategoryFacts;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;

/**
 * Replaces the emergency-fund policy of the calling workspace under optimistic concurrency.
 *
 * A listed category must be an active budget expense category of the caller's own workspace, since
 * only budget expenses enter the essential average; an unknown, foreign, income, unbudgeted or
 * archived id is refused with one and the same error, so the answer never tells whether an id
 * exists elsewhere. A category archived after it was listed may stay listed.
 */
final readonly class UpdateEmergencyFundPolicy
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private EmergencyFundPolicyRepository $policies,
        private ReadBudgetCategoryFacts $categories,
        private WorkspaceCalendar $calendar,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
    ) {
    }

    public function __invoke(EmergencyFundPolicyInput $input): EmergencyFundPolicyView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner manages the emergency-fund policy.');
        }
        $workspace = $context->workspace;
        $essentialMode = EssentialMode::tryFrom($input->essentialMode) ?? throw new EmergencyFundInputError('invalid_emergency_fund_policy', 'The essential mode is AXIS or CATEGORIES.');
        $referenceMode = ReferenceMode::tryFrom($input->referenceMode) ?? throw new EmergencyFundInputError('invalid_emergency_fund_policy', 'The reference mode is PREVIOUS_YEAR or TRAILING_MONTHS.');
        try {
            $target = DecimalValue::fromString($input->targetMonths);
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw new EmergencyFundInputError('invalid_target_months', 'A target is 0 to 60 months with at most one decimal.');
        }
        $now = $this->calendar->now();
        $revise = static fn (EmergencyFundPolicy $policy): EmergencyFundPolicy => $policy->revise($essentialMode, $input->essentialCategoryIds, $referenceMode, $input->trailingMonths, $target, $now, $context->actorId);
        try {
            $revise(EmergencyFundPolicy::default($workspace));
        } catch (InvalidEmergencyFundPolicy $exception) {
            throw new EmergencyFundInputError($exception->errorCode, $exception->getMessage());
        }
        $categories = [] === $input->essentialCategoryIds ? [] : ($this->categories)($workspace);

        return $this->transactions->transactional(function () use ($workspace, $input, $revise, $categories, $context): EmergencyFundPolicyView {
            $current = $this->policies->find($workspace) ?? EmergencyFundPolicy::default($workspace);
            if ($current->version !== $input->expectedVersion) {
                throw new EmergencyFundConflict('stale_emergency_fund_policy', 'The emergency-fund policy changed; reload and try again.');
            }
            self::assertListable($input->essentialCategoryIds, $categories, $current);
            $revised = $revise($current);
            $changed = $current->changedFields($revised);
            if ([] === $changed) {
                return EmergencyFundPolicyView::of($current, $categories);
            }
            if (!$this->policies->save($revised, $current->version)) {
                throw new EmergencyFundConflict('stale_emergency_fund_policy', 'The emergency-fund policy changed; reload and try again.');
            }
            ($this->audit)(new AuditEventRecord(
                $workspace,
                $context->actorId,
                EmergencyFundAuditEvents::POLICY_UPDATED,
                EmergencyFundAuditEvents::POLICY_ENTITY,
                $workspace->id,
                AuditDiff::change(['version' => $current->version], ['version' => $revised->version, 'changedFields' => implode(',', $changed)]),
            ));

            return EmergencyFundPolicyView::of($revised, $categories);
        });
    }

    /**
     * @param list<string>             $ids
     * @param list<BudgetCategoryFact> $categories the caller's own categories
     */
    private static function assertListable(array $ids, array $categories, EmergencyFundPolicy $current): void
    {
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->id] = $category;
        }
        $alreadyListed = array_flip($current->essentialCategoryIds);
        foreach ($ids as $id) {
            $category = $byId[$id] ?? null;
            if (null === $category || 'EXPENSE' !== $category->type || !$category->budgetIncluded || (null !== $category->archivedAt && !isset($alreadyListed[$id]))) {
                throw new EmergencyFundInputError('invalid_emergency_fund_category', 'An essential category is an active budget expense category of this workspace.');
            }
        }
    }
}
