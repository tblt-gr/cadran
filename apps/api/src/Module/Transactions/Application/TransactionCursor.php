<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Transactions\Domain\TransactionPosition;

final readonly class TransactionCursor
{
    public function __construct(public \DateTimeImmutable $bookedOn, public string $id)
    {
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode($this->bookedOn->format('Y-m-d').' '.$this->id), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parts = false === $decoded ? [] : explode(' ', $decoded);
        if (2 !== count($parts) || 1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $parts[1])) {
            throw new InvalidTransactionInput('The transaction cursor is invalid.');
        }
        try {
            return new self(BusinessDay::fromIsoDate($parts[0])->date, $parts[1]);
        } catch (\Throwable $exception) {
            throw new InvalidTransactionInput('The transaction cursor is invalid.', previous: $exception);
        }
    }

    public function position(): TransactionPosition
    {
        return new TransactionPosition($this->bookedOn, $this->id);
    }
}
