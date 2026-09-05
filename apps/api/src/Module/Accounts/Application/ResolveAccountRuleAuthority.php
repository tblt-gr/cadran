<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRuleAuthority;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Catalog\Application\ProductCatalog;

/**
 * Finds what an account inherits its rules from, for the one decision that
 * needs it: whether a submitted override is something that authority could
 * itself have stated.
 *
 * An account that follows neither a product nor a model has no authority at
 * all, and recording an override on it is refused rather than accepted as a
 * free-standing local rule. Otherwise the endpoint would be a way to attach a
 * rate to an account whose product promises none simply by detaching the
 * product first.
 */
final readonly class ResolveAccountRuleAuthority
{
    public function __construct(
        private ProductCatalog $catalog,
        private ProductModelRepository $models,
    ) {
    }

    public function of(Account $account): AccountRuleAuthority
    {
        if (null !== $account->productModelId) {
            $model = $this->models->find($account->workspace, $account->productModelId);
            if (null === $model) {
                throw new \LogicException('An account references a product model that no longer exists.');
            }

            return AccountRuleAuthority::ofModel($model);
        }

        $productCode = $account->productCode;
        if (null === $productCode) {
            throw new InvalidAccountRuleOverrideInput('This account follows no product or model, so it inherits no rule to override.');
        }

        // A product the catalogue has withdrawn no longer states what the
        // account may carry. Recording against it would mean checking a new
        // local claim against nothing at all.
        $entry = $this->catalog->findByCode($productCode)
            ?? throw new InvalidAccountRuleOverrideInput('The catalogue no longer describes the product this account follows, so no new override can be checked against it.');

        return AccountRuleAuthority::ofProduct($entry->product);
    }
}
