<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * A named function that a product model enables.
 *
 * The set is deliberately closed in code: a genuinely new function needs its
 * own reviewed domain behavior. Product rows may freely reuse these known
 * capabilities without a schema change or an unvalidated configuration blob.
 */
enum ProductCapability: string
{
    case SUPPORTS_BALANCE = 'SUPPORTS_BALANCE';
    case SUPPORTS_TRANSACTIONS = 'SUPPORTS_TRANSACTIONS';
    case SUPPORTS_INTEREST = 'SUPPORTS_INTEREST';
    case SUPPORTS_HOLDINGS = 'SUPPORTS_HOLDINGS';
    case SUPPORTS_TRADES = 'SUPPORTS_TRADES';
    case SUPPORTS_ARBITRAGE = 'SUPPORTS_ARBITRAGE';
    case SUPPORTS_CONTRIBUTIONS = 'SUPPORTS_CONTRIBUTIONS';
    case SUPPORTS_FEES = 'SUPPORTS_FEES';
    case SUPPORTS_TAX_TRACKING = 'SUPPORTS_TAX_TRACKING';
    case SUPPORTS_LIABILITY = 'SUPPORTS_LIABILITY';
}
