<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

final readonly class Workspace
{
    public const string DEFAULT_TIMEZONE = 'Europe/Paris';

    public function __construct(
        public string $id,
        public string $name,
        public string $timezone,
        public string $baseCurrency,
        public \DateTimeImmutable $createdAt,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A workspace requires an identifier.');
        }

        if ('' === trim($name) || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('A workspace name must be between 1 and 100 characters.');
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('A workspace timezone must be an IANA time zone identifier.');
        }

        // Shape only; validation against the ISO 4217 code list belongs to the
        // currency reference data, not to this entity.
        if (1 !== preg_match('/^[A-Z]{3}$/D', $baseCurrency)) {
            throw new \InvalidArgumentException('A workspace base currency must be three uppercase letters.');
        }
    }
}
