<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * Which side of the balance sheet a product sits on.
 *
 * Net worth adds one and subtracts the other, so the side is stated rather
 * than guessed from a name or a sign: a loan holds a positive outstanding
 * amount and still reduces what its holder owns.
 */
enum ProductNature: string
{
    case ASSET = 'ASSET';
    case LIABILITY = 'LIABILITY';
}
