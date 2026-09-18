<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final readonly class CreateTransactionInput
{
    /**
     * $splits, when not null, replaces the $categoryId shorthand with an
     * explicit multi-row allocation; $categoryId then stays accepted only as
     * a convenience for the common single-category case.
     *
     * @param ?list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> $splits
     */
    public function __construct(
        public string $accountId,
        public mixed $amount,
        public string $nature,
        public string $state,
        public string $bookedOn,
        public ?string $valueOn,
        public ?string $authorizedOn,
        public string $rawLabel,
        public ?string $counterparty,
        public ?string $note,
        public ?string $paymentMethod,
        public ?string $mcc,
        public ?string $maskedCard,
        public ?string $bankReference,
        public ?string $categoryId,
        public ?array $splits,
        public string $source = 'MANUAL',
        /** The provider's own identifier for this movement, when it has one. */
        public ?string $sourceRef = null,
        /**
         * Whether this body is an incoming booked movement to reconcile
         * against the account's pending rows rather than a new entry. Opt-in:
         * an ordinary entry must never be downgraded to a review.
         */
        public bool $reconcile = false,
        /**
         * False for a row the system writes on the owner's behalf (a balance
         * adjustment): no user rule may categorise it and no recurrence may
         * claim it.
         */
        public bool $automation = true,
    ) {
    }
}
