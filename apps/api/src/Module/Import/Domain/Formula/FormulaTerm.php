<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Formula;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * One top-level term of an amount formula: one movement.
 *
 * `value` is the signed result at the asset precision (a term preceded by a
 * top-level `-` is negative). For a pure product `q*p`, `isProduct` is true and
 * `factors` holds each evaluated factor as written (`3*95.12` gives `3` and
 * `95.12`); every other term has `isProduct` false and its value as sole factor.
 * `exact` is set only when a product was rounded to the asset precision (a
 * purchase gross): it is the unrounded product, which the cached value of the
 * cell is checked against.
 */
final readonly class FormulaTerm
{
    /**
     * @param list<DecimalValue> $factors
     */
    public function __construct(
        public DecimalValue $value,
        public bool $isProduct,
        public array $factors,
        public ?DecimalValue $exact = null,
    ) {
    }
}
