<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\CategoryRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Categorization\InvalidCategorizationRule;
use App\Module\Transactions\Domain\Categorization\RuleConditions;
use App\Module\Transactions\Domain\Categorization\UnsafeRulePattern;

final readonly class CategorizationRuleFactory
{
    public function __construct(private AccountRepository $accounts, private CategoryRepository $categories)
    {
    }

    public function create(string $id, WorkspaceScope $workspace, CategorizationRuleInput $input, \DateTimeImmutable $now): CategorizationRule
    {
        [$conditions, $axes, $from, $to] = $this->parse($workspace, $input);

        try {
            return new CategorizationRule(
                $id, $workspace, $input->label, $input->priority, $input->accountScope, $conditions,
                $input->targetCategoryId, $axes, $input->targetCounterparty, $from, $to, true, null, 0, 1, $now, $now, null,
            );
        } catch (InvalidCategorizationRule $exception) {
            throw new InvalidCategorizationRuleInput('The categorization rule input is invalid.', previous: $exception);
        }
    }

    public function revise(CategorizationRule $current, CategorizationRuleInput $input, \DateTimeImmutable $now): CategorizationRule
    {
        [$conditions, $axes, $from, $to] = $this->parse($current->workspace, $input, $current->accountScope);

        try {
            return $current->revise(
                $input->label, $input->priority, $input->accountScope, $conditions, $input->targetCategoryId,
                $axes, $input->targetCounterparty, $from, $to, $input->active, $now,
            );
        } catch (InvalidCategorizationRule $exception) {
            throw new InvalidCategorizationRuleInput('The categorization rule input is invalid.', previous: $exception);
        }
    }

    /**
     * An account closed or archived after the rule was saved must not lock the
     * rule out of every later edit, deactivation included: only accounts newly
     * added to the scope are checked.
     *
     * @param list<string> $keptAccountIds
     *
     * @return array{RuleConditions, list<AnalyticAxis>, \DateTimeImmutable, ?\DateTimeImmutable}
     */
    private function parse(WorkspaceScope $workspace, CategorizationRuleInput $input, array $keptAccountIds = []): array
    {
        if (count($input->accountScope) !== count(array_unique($input->accountScope))) {
            throw new InvalidCategorizationReference();
        }
        $category = $this->categories->findForUpdate($workspace, $input->targetCategoryId);
        if (null === $category || null !== $category->archivedAt) {
            throw new InvalidCategorizationReference();
        }
        foreach (array_diff($input->accountScope, $keptAccountIds) as $accountId) {
            $account = $this->accounts->findForUpdate($workspace, $accountId);
            if (null === $account || null !== $account->archivedAt || $account->isClosed()) {
                throw new InvalidCategorizationReference();
            }
        }
        try {
            $conditions = RuleConditions::fromDocument($input->conditions);
            $axes = array_map(static fn (string $axis): AnalyticAxis => AnalyticAxis::from($axis), $input->targetAxes);
            $from = self::day($input->effectiveFrom);
            $to = null === $input->effectiveTo ? null : self::day($input->effectiveTo);
        } catch (UnsafeRulePattern $exception) {
            throw $exception;
        } catch (\ValueError|InvalidCategorizationRule $exception) {
            throw new InvalidCategorizationRuleInput('The categorization rule input is invalid.', previous: $exception);
        }

        return [$conditions, $axes, $from, $to];
    }

    private static function day(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new InvalidCategorizationRule('A categorization date must use YYYY-MM-DD.');
        }

        return $date;
    }
}
