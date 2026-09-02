<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * How a rule's value is written down.
 *
 * A PERCENTAGE is expressed in percent — `1.7` reads 1.7 % — so no consumer
 * ever multiplies a rate to display it. The catalogue transports exact
 * decimals; it performs no arithmetic on them.
 */
enum RuleValueType: string
{
    case AMOUNT = 'AMOUNT';
    case PERCENTAGE = 'PERCENTAGE';
    case TEXT = 'TEXT';
}
