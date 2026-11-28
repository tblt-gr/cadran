<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\RuleDeactivationReason;

/** The protective deactivation of the rules that exceeded their pattern budget during a run. */
final readonly class DeactivateTrippedRules
{
    public function __construct(private CategorizationRuleRepository $rules, private RecordAuditEvent $audit)
    {
    }

    /**
     * @param list<CategorizationRule> $evaluated      the rules as the run read them
     * @param list<string>             $trippedRuleIds
     *
     * @return list<string> the rules this call deactivated
     *
     * @throws StaleCategorizationRule when a tripped rule was edited since the run read it
     */
    public function __invoke(WorkspaceScope $workspace, array $evaluated, array $trippedRuleIds, ?string $actorId, \DateTimeImmutable $now): array
    {
        $deactivatedIds = [];
        foreach ($evaluated as $rule) {
            if (!in_array($rule->id, $trippedRuleIds, true)) {
                continue;
            }
            $current = $this->rules->findForUpdate($workspace, $rule->id);
            if (null === $current || !$current->active) {
                continue;
            }
            // A preview holds no workspace lock: an edit may have replaced the pattern that tripped.
            if ($current->version !== $rule->version
                || !$this->rules->update($current->deactivate(RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED, $now), $rule->version)) {
                throw new StaleCategorizationRule();
            }
            $deactivatedIds[] = $rule->id;
            ($this->audit)(new AuditEventRecord($workspace, $actorId, 'categorization_rule.deactivated', 'categorization_rule', $rule->id, AuditDiff::change(['active' => true], ['active' => false, 'reason' => RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED->value])));
        }

        return $deactivatedIds;
    }
}
