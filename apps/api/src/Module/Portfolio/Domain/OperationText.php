<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

/** Free text of an operation: trimmed, bounded, and free of control and format characters. */
final class OperationText
{
    public const int MAX_REFERENCE = 64;
    public const int MAX_NOTE = 200;
    public const int MAX_REASON = 200;

    public static function normalize(?string $text, int $maxLength): ?string
    {
        if (null === $text) {
            return null;
        }
        $trimmed = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $text);
        if (null === $trimmed || '' === $trimmed || mb_strlen($trimmed) > $maxLength || 1 === preg_match('/[\p{Cc}\p{Cf}]/u', $trimmed)) {
            throw new InvalidPortfolioOperation(sprintf('Operation text must be 1 to %d plain characters.', $maxLength), 'invalid_operation_text');
        }

        return $trimmed;
    }
}
