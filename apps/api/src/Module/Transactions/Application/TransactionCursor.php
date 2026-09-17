<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Transactions\Domain\TransactionPosition;
use App\Module\Transactions\Domain\TransactionWatermark;

final readonly class TransactionCursor
{
    private const string WATERMARK_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public \DateTimeImmutable $bookedOn,
        public string $id,
        public TransactionWatermark $watermark,
    ) {
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(implode(' ', [
            $this->bookedOn->format('Y-m-d'),
            $this->id,
            $this->watermark->updatedAt->format(self::WATERMARK_TIMESTAMP_FORMAT),
            $this->watermark->id,
        ])), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        $parts = false === $decoded ? [] : explode(' ', $decoded);
        if (4 !== count($parts)
            || 1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $parts[1])
            || 1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $parts[3])
        ) {
            throw new InvalidTransactionInput('The transaction cursor is invalid.');
        }
        try {
            $bookedOn = BusinessDay::fromIsoDate($parts[0])->date;
            $watermarkUpdatedAt = \DateTimeImmutable::createFromFormat(self::WATERMARK_TIMESTAMP_FORMAT, $parts[2]);
            if (false === $watermarkUpdatedAt) {
                throw new InvalidTransactionInput('The transaction cursor is invalid.');
            }

            return new self($bookedOn, $parts[1], new TransactionWatermark($watermarkUpdatedAt, $parts[3]));
        } catch (InvalidTransactionInput $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidTransactionInput('The transaction cursor is invalid.', previous: $exception);
        }
    }

    public function position(): TransactionPosition
    {
        return new TransactionPosition($this->bookedOn, $this->id);
    }
}
