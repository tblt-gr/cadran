<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class TransactionSplit
{
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $transactionId,
        public string $categoryId,
        public AssetAmount $amount,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
    ) {
        self::identifier($id);
        self::identifier($transactionId);
        self::identifier($categoryId);
        self::optionalText($note, 140, 'split note');
        if (0 === $amount->value->compareTo(\App\Module\Foundation\Domain\DecimalValue::zero())) {
            throw new InvalidTransaction('A split amount cannot be zero.');
        }
    }

    private static function identifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidTransaction('A transaction split identifier must be a canonical UUID.');
        }
    }

    private static function optionalText(?string $value, int $maximum, string $field): void
    {
        if (null !== $value && ($value !== trim($value) || '' === $value || mb_strlen($value) > $maximum)) {
            throw new InvalidTransaction(sprintf('A %s must contain between 1 and %d characters.', $field, $maximum));
        }
    }
}
