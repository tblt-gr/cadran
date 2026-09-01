<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The requested workspace base currency is not a currency of the system asset
 * reference. The message names no submitted value: it is echoed to a console
 * and into container logs.
 */
final class UnsupportedBaseCurrency extends \InvalidArgumentException
{
}
