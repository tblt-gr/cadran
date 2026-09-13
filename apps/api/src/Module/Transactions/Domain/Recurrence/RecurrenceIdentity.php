<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * The checks a recurrence and its occurrences state the same way, kept in one
 * place so the entity and the forecast it generates cannot drift apart.
 *
 * @internal to the recurrence domain
 */
final class RecurrenceIdentity
{
    public static function identifier(string $id, string $field): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidRecurrence(sprintf('A recurrence %s must be a canonical UUID.', $field));
        }
    }

    public static function text(string $value, int $maximum, string $field): void
    {
        if ($value !== trim($value) || '' === $value || mb_strlen($value) > $maximum) {
            throw new InvalidRecurrence(sprintf('A recurrence %s must contain between 1 and %d characters.', $field, $maximum));
        }
    }

    /**
     * An expected amount is never zero and its tolerance is never negative, and
     * both are denominated in the same asset: a tolerance in another unit would
     * compare two figures that cannot be compared.
     */
    public static function expectation(AssetAmount $expectedAmount, AssetAmount $amountTolerance): void
    {
        if (ExactDecimal::isZero($expectedAmount->value)) {
            throw new InvalidRecurrence('A recurrence expects a non-zero amount.');
        }
        if ($amountTolerance->value->compareTo(DecimalValue::zero()) < 0) {
            throw new InvalidRecurrence('A recurrence tolerance cannot be negative.');
        }
        if (!$amountTolerance->asset->equals($expectedAmount->asset)) {
            throw new InvalidRecurrence('A recurrence tolerance is denominated in the expected amount asset.');
        }
    }
}
