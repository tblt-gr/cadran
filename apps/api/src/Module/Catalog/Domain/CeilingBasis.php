<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * What a product's ceiling is measured against.
 *
 * The distinction is not cosmetic: a PEA is capped on the contributions paid
 * into it, whatever the plan is worth, so a plan that grew past its ceiling
 * through market value has broken no rule. A regulated passbook is capped on
 * the balance it holds instead. Stating the basis on the server keeps any
 * screen from guessing it from a product name or from which rule happens to be
 * sourced on the day it is read.
 */
enum CeilingBasis: string
{
    /** No ceiling is tracked for this product. */
    case NONE = 'NONE';
    /** The deposited balance, interest excluded. */
    case BALANCE = 'BALANCE';
    /** Cumulative contributions, whatever the account is worth. */
    case CONTRIBUTIONS = 'CONTRIBUTIONS';
}
