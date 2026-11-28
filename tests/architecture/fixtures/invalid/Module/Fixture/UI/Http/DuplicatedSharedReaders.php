<?php

declare(strict_types=1);

namespace App\Module\Fixture\UI\Http;

final class DuplicatedSharedReaders
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}$/D';
    private const int MAX_PAGE = 1000;

    private static function text(mixed $value): string
    {
        return (string) $value;
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value;
    }
}
