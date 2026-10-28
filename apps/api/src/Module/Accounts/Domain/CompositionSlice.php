<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/** One signed component of a group and its weight against the group's own total. */
final readonly class CompositionSlice
{
    public function __construct(
        public ?DecimalValue $value,
        public NetWorthShare $share,
    ) {
    }
}
