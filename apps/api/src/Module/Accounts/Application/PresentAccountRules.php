<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountCeiling;
use App\Module\Accounts\Domain\AccountRate;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\AccountTerm;
use App\Module\Foundation\Application\CallerWorkspace;

/**
 * Loads the figures a rules response needs besides the resolution itself:
 * the latest valuation on the business date, and the names of override
 * authors.
 */
final readonly class PresentAccountRules
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private ResolveAccountValuations $valuations,
        private ResolveAuthorNames $authors,
    ) {
    }

    public function __invoke(AccountRules $rules): AccountRulePresentation
    {
        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $rules->accountId) ?? throw new AccountNotFound();
        $valuation = $this->valuations->forAccounts($workspace, [$account], [$rules->asOf])[$rules->asOf->format('Y-m-d')][$account->id];

        return new AccountRulePresentation($valuation, $this->authors->forIds(self::authorIds($rules)));
    }

    /**
     * @return list<string>
     */
    private static function authorIds(AccountRules $rules): array
    {
        $ids = [];
        foreach ($rules->ceilings as $rule) {
            self::collectCeiling($ids, $rule->catalog);
            self::collectCeiling($ids, $rule->inherited);
            self::collectCeiling($ids, $rule->override);
        }

        foreach ($rules->rates as $rule) {
            self::collectRate($ids, $rule->catalog);
            self::collectRate($ids, $rule->inherited);
            self::collectRate($ids, $rule->override);
        }

        foreach ($rules->terms as $rule) {
            self::collectTerm($ids, $rule->catalog);
            self::collectTerm($ids, $rule->inherited);
            self::collectTerm($ids, $rule->override);
        }

        return $ids;
    }

    /** @param list<string> $ids */
    private static function collectCeiling(array &$ids, ?AccountCeiling $ceiling): void
    {
        if (null !== $ceiling?->override) {
            $ids[] = $ceiling->override->authorId;
        }
    }

    /** @param list<string> $ids */
    private static function collectRate(array &$ids, ?AccountRate $rate): void
    {
        if (null !== $rate?->override) {
            $ids[] = $rate->override->authorId;
        }
    }

    /** @param list<string> $ids */
    private static function collectTerm(array &$ids, ?AccountTerm $term): void
    {
        if (null !== $term?->override) {
            $ids[] = $term->override->authorId;
        }
    }
}
