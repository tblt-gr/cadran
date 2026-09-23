<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final readonly class MonthlyLedgerCursor
{
    public function __construct(
        public string $month,
        public string $kind,
        public string $rowId,
        public ?string $axis,
        public string $bookedOn,
        public string $sourceId,
    ) {
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(implode(' ', [
            $this->month,
            $this->kind,
            $this->rowId,
            $this->axis ?? '-',
            $this->bookedOn,
            $this->sourceId,
        ])), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parts = false === $decoded ? [] : explode(' ', $decoded);
        if (6 !== count($parts)
            || 1 !== preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $parts[0])
            || !in_array($parts[1], ['income', 'expense', 'account'], true)
            || !self::uuid($parts[2])
            || 1 !== preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $parts[4])
            || !self::uuid($parts[5])
        ) {
            throw new InvalidMonthlyLedgerQuery('The monthly ledger cursor is invalid.');
        }

        return new self($parts[0], $parts[1], $parts[2], '-' === $parts[3] ? null : $parts[3], $parts[4], $parts[5]);
    }

    private static function uuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $value);
    }
}
