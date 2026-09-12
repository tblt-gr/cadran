<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\CategorizationSubject;
use App\Module\Transactions\Domain\Categorization\RegexSafetyPolicy;

/**
 * The execution bound of one categorisation run. Every pattern evaluation runs
 * under a PCRE backtrack limit and is timed against a cumulated per-rule
 * budget; a rule that trips either bound stops matching for the rest of the
 * run and is reported, so the caller can deactivate it instead of retrying.
 */
final class RuleMatchingRun
{
    public const int BACKTRACK_LIMIT = 100_000;
    public const int BUDGET_NANOSECONDS = 50_000_000;

    /** @var array<string, int> */
    private array $spent = [];
    /** @var array<string, true> */
    private array $tripped = [];

    public function __construct(private readonly ElapsedTime $clock)
    {
    }

    public function matches(CategorizationRule $rule, CategorizationSubject $subject): bool
    {
        if (isset($this->tripped[$rule->id])) {
            return false;
        }

        try {
            return $rule->conditions->matches($subject, fn (string $pattern, string $text): bool => $this->evaluate($rule->id, $pattern, $text));
        } catch (PatternBudgetExceeded) {
            $this->tripped[$rule->id] = true;

            return false;
        }
    }

    /** @return list<string> */
    public function trippedRuleIds(): array
    {
        return array_map(strval(...), array_keys($this->tripped));
    }

    private function evaluate(string $ruleId, string $pattern, string $text): bool
    {
        $previousLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);
        $startedAt = $this->clock->nanoseconds();
        try {
            $result = @preg_match(RegexSafetyPolicy::compile($pattern), $text);
        } finally {
            ini_restore('pcre.backtrack_limit');
            if (false !== $previousLimit) {
                ini_set('pcre.backtrack_limit', $previousLimit);
            }
        }
        $this->spent[$ruleId] = ($this->spent[$ruleId] ?? 0) + $this->clock->nanoseconds() - $startedAt;
        if (false === $result || $this->spent[$ruleId] > self::BUDGET_NANOSECONDS) {
            throw new PatternBudgetExceeded();
        }

        return 1 === $result;
    }
}
