<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCode;

/**
 * Binds an account to the catalogue product it is created from.
 *
 * The submitted code is never trusted as a label: it is looked up, and the
 * account attributes the client sent are checked against what the catalogue
 * actually declares. That is what stops a form from activating a capability the
 * product does not carry, or from filing a PEA as a savings account so a later
 * ceiling is read on the wrong basis.
 *
 * The catalogue is a global system reference with no `workspace_id`: a product
 * code either exists for every workspace or for none. One refusal therefore
 * covers both an unknown code and a code belonging to another workspace. The
 * database repeats the reference and the kind together, through a foreign key
 * on `catalog_products (code, account_kind)`, so a writer that does not come
 * through here cannot file a product under a kind it does not declare either.
 * The capability check below has no such counterpart and lives only here.
 */
final readonly class AccountProduct
{
    public function __construct(private ProductCatalog $catalog)
    {
    }

    public function resolve(?string $code, AccountKind $kind, AccountValuationMode $valuationMode): ?ProductCode
    {
        if (null === $code) {
            return null;
        }

        try {
            $productCode = ProductCode::fromString($code);
        } catch (InvalidCatalogEntry $failure) {
            // A code that cannot exist and a code that does not exist answer
            // alike, so a client cannot probe the catalogue through the shape
            // of the refusal.
            throw new InvalidAccountInput('The account product must exist in the system catalogue.', previous: $failure);
        }

        // An archived product is deliberately invisible here: the catalogue
        // stopped vouching for it, so it cannot back a new account. An account
        // already referencing it keeps its reference; only a change is refused.
        $entry = $this->catalog->findByCode($productCode);
        if (null === $entry) {
            throw new InvalidAccountInput('The account product must exist in the system catalogue.');
        }

        $product = $entry->product;

        if ($product->accountKind !== $kind) {
            throw new InvalidAccountInput('The account kind must be the one its product declares.');
        }

        if (!$product->capabilities->contains($valuationMode->requiredCapability())) {
            throw new InvalidAccountInput('The account valuation mode requires a capability this product does not declare.');
        }

        return $productCode;
    }
}
