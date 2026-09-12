<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class RefundableView
{
    /**
     * @param array{value: string, assetCode: string}                                          $originalAmount
     * @param array{value: string, assetCode: string}                                          $refunded
     * @param array{value: string, assetCode: string}                                          $refundable
     * @param list<array{categoryId: string, amount: array{value: string, assetCode: string}}> $proposedSplits
     */
    public function __construct(
        public string $originalId,
        public array $originalAmount,
        public array $refunded,
        public array $refundable,
        public array $proposedSplits,
    ) {
    }
}
