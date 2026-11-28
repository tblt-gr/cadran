<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Categories\Domain\Category;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionSplit;

/**
 * The automatic categorization of the transactions one use case creates: the rules and their
 * targets are read once, and one execution budget bounds the whole batch.
 */
final class AutoCategorizationBatch
{
    /** @var list<CategorizationRule> */
    private array $rules = [];
    /** @var array<string, Category> */
    private array $targets = [];
    /** @var array<string, ?Category> the winning targets already locked */
    private array $locked = [];
    /** @var array<string, int> persisted categorizations by rule identifier */
    private array $applied = [];
    private readonly RuleMatchingRun $matching;
    private bool $limitExceeded = false;

    public function __construct(
        private readonly WorkspaceScope $workspace,
        private readonly ?string $actorId,
        private readonly \DateTimeImmutable $now,
        private readonly CategorizationRuleRepository $ruleRepository,
        private readonly CategoryRepository $categories,
        private readonly CategorizationResolver $resolver,
        ElapsedTime $elapsedTime,
        CategorizationExecutionBudget $budget,
        private readonly DeactivateTrippedRules $deactivate,
        private readonly UuidGenerator $ids,
    ) {
        $this->matching = new RuleMatchingRun($elapsedTime, $budget->maxRuleEvaluations, $budget->maxRunNanoseconds);
        $rules = $ruleRepository->activeInOrder($workspace, $budget->maxActiveRules + 1);
        if (count($rules) > $budget->maxActiveRules) {
            $this->limitExceeded = true;

            return;
        }
        $this->rules = CategorizationResolver::ordered($rules);
        foreach (array_unique(array_map(static fn (CategorizationRule $rule): string => $rule->targetCategoryId, $this->rules)) as $categoryId) {
            $category = $categories->find($workspace, $categoryId);
            if (null !== $category) {
                $this->targets[$categoryId] = $category;
            }
        }
    }

    /** Returns the transaction untouched when it is already categorized, when no rule wins or past a limit. */
    public function categorize(Transaction $transaction): Transaction
    {
        if ($this->limitExceeded || [] !== $transaction->splits) {
            return $transaction;
        }
        $subject = CategorizationSubject::fromTransaction($transaction);
        try {
            $winner = $this->resolver->resolve($this->rules, $this->targets, $subject, $this->matching, true)->winner;
        } catch (CategorizationExecutionLimitExceeded) {
            $this->limitExceeded = true;

            return $transaction;
        }
        if (null === $winner || !CategorizationResolver::targetFits($this->lockedTarget($winner->targetCategoryId), $subject)) {
            return $transaction;
        }
        $split = new TransactionSplit(
            $this->ids->generate(), $transaction->workspace, $transaction->id, $winner->targetCategoryId,
            $transaction->amount, $winner->targetAxes, null, $this->now, 0, CategorizationOrigin::RULE, $winner->id,
        );

        return $transaction->categorize($split, $winner->targetCounterparty, $this->now, false);
    }

    /** To call only once the transaction is persisted, so a refused one is never counted. */
    public function countApplied(Transaction $transaction): void
    {
        $ruleId = ($transaction->splits[0] ?? null)?->ruleId;
        if (null !== $ruleId) {
            $this->applied[$ruleId] = ($this->applied[$ruleId] ?? 0) + 1;
        }
    }

    /** Writes one counter update per winning rule and deactivates the rules that tripped. */
    public function finish(): void
    {
        foreach ($this->applied as $ruleId => $count) {
            $this->ruleRepository->incrementAppliedCount($this->workspace, $ruleId, $count);
        }
        $this->applied = [];
        ($this->deactivate)($this->workspace, $this->rules, $this->matching->trippedRuleIds(), $this->actorId, $this->now);
    }

    private function lockedTarget(string $categoryId): ?Category
    {
        if (!array_key_exists($categoryId, $this->locked)) {
            $this->locked[$categoryId] = $this->categories->findForUpdate($this->workspace, $categoryId);
        }

        return $this->locked[$categoryId];
    }
}
