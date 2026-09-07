<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/**
 * The untrusted members of one submitted amount and their JSON location.
 * Types stay mixed until AmountInputParser has rejected every coercion.
 */
final readonly class AmountInput
{
    public function __construct(
        public mixed $value,
        public mixed $assetCode,
        public string $pointer,
    ) {
    }
}
