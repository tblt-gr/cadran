<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class CreateRefundInput
{
    /** @param ?list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> $splits */
    public function __construct(
        public string $accountId,
        public mixed $amount,
        public string $bookedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $note,
        public ?array $splits,
    ) {
    }
}
