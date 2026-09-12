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
    public function __construct(private CategorizationRuleRepository $rules, private CategoryRepository $categories, private CategorizationResolver $resolver, private ElapsedTime $elapsedTime, private RecordAuditEvent $audit)
    {
    }

    /** @param list<Transaction> $transactions */
    public function __invoke(WorkspaceScope $workspace, array $transactions, ?string $actorId, \DateTimeImmutable $now): CategorizationRun
    {
        $rules = $this->rules->activeInOrder($workspace);
        $targets = $this->targets($workspace, $rules);
        [$resolutions, $tripped] = $this->resolve($transactions, $rules, $targets);
        if ([] !== $tripped) {
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
                ($this->audit)(new AuditEventRecord($workspace, $actorId, 'categorization_rule.deactivated', 'categorization_rule', $rule->id, AuditDiff::change(['active' => true], ['active' => false, 'reason' => RuleDeactivationReason::PATTERN_BUDGET_EXCEEDED->value])));
            }
            $rules = array_values(array_filter($this->rules->activeInOrder($workspace), static fn (CategorizationRule $rule): bool => !in_array($rule->id, $tripped, true)));
            [$resolutions] = $this->resolve($transactions, $rules, $this->targets($workspace, $rules));
        }

        return new CategorizationRun($workspace->id, $rules, $transactions, $resolutions, $tripped);
    }

    /**
     * @param list<Transaction>        $transactions
     * @param list<CategorizationRule> $rules
     * @param array<string, Category>  $targets
     *
     * @return array{array<string, RuleResolution>, list<string>}
     */
    private function resolve(array $transactions, array $rules, array $targets): array
    {
        $matching = new RuleMatchingRun($this->elapsedTime);
        $resolutions = [];
        foreach ($transactions as $transaction) {
            $resolutions[$transaction->id] = $this->resolver->resolve($rules, $targets, CategorizationSubject::fromTransaction($transaction), $matching, false);
        }

        return [$resolutions, $matching->trippedRuleIds()];
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
