<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\RuleDeactivationReason;
use App\Module\Transactions\Domain\Transaction;

final readonly class BuildCategorizationRun
{
    public function __construct(private CategorizationRuleRepository $rules, private CategoryRepository $categories, private CategorizationResolver $resolver, private ElapsedTime $elapsedTime, private CategorizationExecutionBudget $budget, private RecordAuditEvent $audit)
    {
    }

    /** @param list<Transaction> $transactions */
    public function __invoke(WorkspaceScope $workspace, array $transactions, ?string $actorId, \DateTimeImmutable $now): CategorizationRun
    {
        $rules = $this->activeRules($workspace);
        $targets = $this->targets($workspace, $rules);
        $deactivated = [];
        $matching = new RuleMatchingRun($this->elapsedTime, $this->budget->maxRuleEvaluations, $this->budget->maxRunNanoseconds);
        try {
            [$resolutions, $tripped] = $this->resolve($transactions, $rules, $targets, $matching);
        } catch (CategorizationExecutionLimitExceeded $exception) {
            $deactivated = $this->deactivate($workspace, $rules, $exception->trippedRuleIds, $actorId, $now);

            return new CategorizationRun($workspace->id, $this->activeRules($workspace), $transactions, [], $deactivated, true);
        }
        if ([] !== $tripped) {
            $deactivated = $this->deactivate($workspace, $rules, $tripped, $actorId, $now);
            $rules = array_values(array_filter($this->activeRules($workspace), static fn (CategorizationRule $rule): bool => !in_array($rule->id, $tripped, true)));
            try {
                [$resolutions, $trippedAgain] = $this->resolve($transactions, $rules, $this->targets($workspace, $rules), $matching->nextPass());
            } catch (CategorizationExecutionLimitExceeded $exception) {
                $laterDeactivated = $this->deactivate($workspace, $rules, $exception->trippedRuleIds, $actorId, $now);

                return new CategorizationRun($workspace->id, $this->activeRules($workspace), $transactions, [], array_values(array_unique([...$deactivated, ...$laterDeactivated])), true);
            }
            // A rule tripping midway through the second pass has already matched, and possibly won,
            // the candidates evaluated before the trip. Every rule was evaluated for each candidate,
            // so dropping it from each resolution is exact and needs no third pass.
            $laterTripped = array_values(array_diff($trippedAgain, $tripped));
            if ([] !== $laterTripped) {
                $deactivated = array_values(array_unique([...$deactivated, ...$this->deactivate($workspace, $rules, $laterTripped, $actorId, $now)]));
                $rules = array_values(array_filter($rules, static fn (CategorizationRule $rule): bool => !in_array($rule->id, $laterTripped, true)));
                $rulesById = array_combine(array_map(static fn (CategorizationRule $rule): string => $rule->id, $rules), $rules);
                $resolutions = array_map(static fn (RuleResolution $resolution): RuleResolution => $resolution->withoutRules($laterTripped, $rulesById), $resolutions);
            }
        }

        return new CategorizationRun($workspace->id, $rules, $transactions, $resolutions, $deactivated);
    }

    /** @return list<CategorizationRule> */
    private function activeRules(WorkspaceScope $workspace): array
    {
        $rules = $this->rules->activeInOrder($workspace, $this->budget->maxActiveRules + 1);
        if (count($rules) > $this->budget->maxActiveRules) {
            throw new CategorizationExecutionLimitExceeded();
        }

        return $rules;
    }

    /**
     * @param list<Transaction>        $transactions
     * @param list<CategorizationRule> $rules
     * @param array<string, Category>  $targets
     *
     * @return array{array<string, RuleResolution>, list<string>}
     */
    private function resolve(array $transactions, array $rules, array $targets, RuleMatchingRun $matching): array
    {
        $resolutions = [];
        foreach ($transactions as $transaction) {
            $resolutions[$transaction->id] = $this->resolver->resolve($rules, $targets, CategorizationSubject::fromTransaction($transaction), $matching, false);
        }

        return [$resolutions, $matching->trippedRuleIds()];
    }

    /**
     * @param list<CategorizationRule> $rules
     * @param list<string>             $tripped
     *
     * @return list<string>
     */
    private function deactivate(WorkspaceScope $workspace, array $rules, array $tripped, ?string $actorId, \DateTimeImmutable $now): array
    {
        $deactivatedIds = [];
        foreach ($rules as $rule) {
            if (!in_array($rule->id, $tripped, true)) {
                continue;
            }
            $current = $this->rules->findForUpdate($workspace, $rule->id);
            if (null === $current || !$current->active) {
                continue;
            }
            $deactivated = $current->deactivate(RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED, $now);
            if (!$this->rules->update($deactivated, $current->version)) {
                throw new StaleCategorizationRule();
            }
            $deactivatedIds[] = $rule->id;
            ($this->audit)(new AuditEventRecord($workspace, $actorId, 'categorization_rule.deactivated', 'categorization_rule', $rule->id, AuditDiff::change(['active' => true], ['active' => false, 'reason' => RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED->value])));
        }

        return $deactivatedIds;
    }

    /**
     * @param list<CategorizationRule> $rules
     *
     * @return array<string, Category>
     */
    private function targets(WorkspaceScope $workspace, array $rules): array
    {
        $targets = [];
        foreach ($rules as $rule) {
            $category = $this->categories->findForUpdate($workspace, $rule->targetCategoryId);
            if (null !== $category) {
                $targets[$category->id] = $category;
            }
        }

        return $targets;
    }
}
