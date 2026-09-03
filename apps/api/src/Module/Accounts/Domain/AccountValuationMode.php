<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCapability;

/**
 * How the value of an account is established.
 *
 * The mode decides which later module owns the figure: recorded movements,
 * dated valuations entered by hand, or positions valued by the portfolio. It
 * is therefore closed, and an account whose mode does not match what its kind
 * can hold is refused rather than valued by a fallback.
 */
enum AccountValuationMode: string
{
    case TRANSACTIONS = 'TRANSACTIONS';
    case SNAPSHOTS = 'SNAPSHOTS';
    case PORTFOLIO = 'PORTFOLIO';

    /**
     * Positions can only be valued where the product actually holds them. A
     * current account or a loan valued "by portfolio" would wait forever for a
     * holding that will never exist.
     */
    public function acceptsKind(AccountKind $kind): bool
    {
        if (self::PORTFOLIO !== $this) {
            return true;
        }

        return in_array($kind, [
            AccountKind::PORTFOLIO,
            AccountKind::INSURANCE_CONTRACT,
            AccountKind::EMPLOYEE_BENEFIT,
        ], true);
    }

    /**
     * The product capability this mode consumes. A product model that does not
     * declare it cannot feed the mode: valuing a Livret A "by portfolio" would
     * wait on holdings the product never activates.
     */
    public function requiredCapability(): ProductCapability
    {
        return match ($this) {
            self::TRANSACTIONS => ProductCapability::SUPPORTS_TRANSACTIONS,
            self::SNAPSHOTS => ProductCapability::SUPPORTS_BALANCE,
            self::PORTFOLIO => ProductCapability::SUPPORTS_HOLDINGS,
        };
    }
}
