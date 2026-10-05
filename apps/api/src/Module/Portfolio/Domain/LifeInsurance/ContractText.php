<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

/** Plain, trimmed, bounded text for the free fields of a contract. */
final class ContractText
{
    public static function required(string $text, int $maxLength): string
    {
        $trimmed = trim($text);
        if ('' === $trimmed || mb_strlen($trimmed) > $maxLength || 1 === preg_match('/[\p{C}]/u', $trimmed)) {
            throw new InvalidLifeInsurance('A contract text field is plain text within its length.', 'invalid_contract_field');
        }

        return $trimmed;
    }

    public static function optional(?string $text, int $maxLength): ?string
    {
        return null === $text ? null : self::required($text, $maxLength);
    }
}
