<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The literal is well formed but carries more digits than the storage type or
 * the asset accepts. PostgreSQL would round it into NUMERIC(50,24) without a
 * word; refusing it keeps the recorded figure equal to the submitted one.
 */
final class PrecisionExceeded extends \InvalidArgumentException
{
}
