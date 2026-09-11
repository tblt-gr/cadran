<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class TransactionSplit
{
    /** @param list<AnalyticAxis> $analyticAxes */
    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $transactionId,
        public string $categoryId,
        public AssetAmount $amount,
        public array $analyticAxes,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
    ) {
        self::identifier($id);
        self::identifier($transactionId);
        self::identifier($categoryId);
        self::optionalText($note, 140, 'split note');
        if (0 === $amount->value->compareTo(DecimalValue::zero())) {
            throw new InvalidTransaction('A split amount cannot be zero.');
        }
        self::assertAxes($analyticAxes);
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

    /** @param list<AnalyticAxis> $axes */
    private static function assertAxes(array $axes): void
    {
        $values = array_map(static fn (AnalyticAxis $axis): string => $axis->value, $axes);
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidTransaction('A split axis cannot be selected twice.');
        }
    }
}
