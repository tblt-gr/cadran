<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\FinancialProduct;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;

/**
 * What an account inherits its rules from, reduced to what deciding an
 * override needs: the capabilities the authority declares and the yield it
 * promises.
 *
 * It exists so that an override cannot state what the authority behind it
 * could not have stated. A catalogue entry refuses a rate on a market product
 * and a ceiling on a product that tracks no balance; a workspace model refuses
 * both the same way. Without this, the override endpoint would be a second,
 * unguarded door onto the same figures — a rate typed onto a PEA, presented
 * beside a published one, and read as a promise nothing backs.
 */
final readonly class AccountRuleAuthority
{
    private function __construct(
        public AccountRulesOrigin $origin,
        public ProductCapabilities $capabilities,
        public YieldKind $yieldKind,
    ) {
    }

    public static function ofProduct(FinancialProduct $product): self
    {
        return new self(AccountRulesOrigin::SYSTEM_CATALOG, $product->capabilities, $product->yieldKind);
    }

    public static function ofModel(ProductModel $model): self
    {
        return new self(AccountRulesOrigin::WORKSPACE_MODEL, $model->capabilities, $model->yieldKind);
    }

    /**
     * @throws InvalidAccountRuleOverride when the authority behind the account
     *                                    could not itself state this rule
     */
    public function assertMayState(RuleKind $kind): void
    {
        if ($kind->statesARate() && !$this->yieldKind->acceptsRateRule()) {
            throw new InvalidAccountRuleOverride(sprintf('A %s account earns no stated rate, so no rate can be recorded against it.', $this->yieldKind->value));
        }

        $required = $kind->requiredCapability();
        if (null !== $required && !$this->capabilities->contains($required)) {
            throw new InvalidAccountRuleOverride(sprintf('%s overrides require %s, which this account does not support.', $kind->value, $required->value));
        }
    }

    /**
     * A local ceiling is checked against this account and nothing else, so
     * unlike a published one it is recorded in the account's own unit. A
     * figure in another unit would need a conversion the application does not
     * hold and could only ever be displayed as not comparable, which is not
     * something a holder would have meant to type.
     */
    public function assertDenominatedIn(AssetAmount $amount, AssetCode $accountAsset): void
    {
        if (!$amount->asset->equals($accountAsset)) {
            throw new InvalidAccountRuleOverride(sprintf('An override amount is denominated in %s, the unit this account is held in.', $accountAsset->toString()));
        }
    }
}
