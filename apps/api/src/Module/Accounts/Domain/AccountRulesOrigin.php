<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Where the rules resolved for an account came from.
 *
 * An account with no rules and an account whose rules could not be read are
 * two different answers, and neither is "this account has no ceiling". Stating
 * the origin keeps a screen from presenting the second as the first.
 */
enum AccountRulesOrigin: string
{
    /** The account is described by hand and inherits no dated rule. */
    case NO_PRODUCT = 'NO_PRODUCT';
    /** The rules were read from the system product catalogue. */
    case SYSTEM_CATALOG = 'SYSTEM_CATALOG';
    /**
     * The account keeps a product reference the catalogue no longer describes.
     * Its recorded history stays valid; the rules behind it cannot be read.
     */
    case PRODUCT_WITHDRAWN = 'PRODUCT_WITHDRAWN';
    /**
     * The rules were read from a reusable product model of the calling
     * workspace. Unlike the system catalogue, the model is never withdrawn
     * from under an account: archiving stops new accounts from starting on
     * it, but one already backed by it keeps resolving the same periods.
     */
    case WORKSPACE_MODEL = 'WORKSPACE_MODEL';
}
