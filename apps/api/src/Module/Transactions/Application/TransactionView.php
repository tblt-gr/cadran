<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\Transaction;

final readonly class TransactionView
{
    /** @param list<array{id: string, categoryId: string, categoryLabel: string, amount: array{value: string, assetCode: string}, note: ?string}> $splits */
    public function __construct(
        public string $id,
        public string $accountId,
        /** @var array{value: string, assetCode: string} */
        public array $amount,
        /** @var array{value: string, assetCode: string}|null */
        public ?array $originalAmount,
        public ?string $exchangeRate,
        public string $nature,
        public string $state,
        public string $source,
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
        public array $splits,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
        public ?string $voidedAt,
    ) {
    }

    /** @param array<string, string> $categoryLabels */
    public static function fromTransaction(Transaction $transaction, array $categoryLabels): self
    {
        return new self(
            id: $transaction->id,
            accountId: $transaction->accountId,
            amount: ['value' => $transaction->amount->value->toString(), 'assetCode' => $transaction->amount->asset->toString()],
            originalAmount: null === $transaction->originalAmount ? null : [
                'value' => $transaction->originalAmount->value->toString(),
                'assetCode' => $transaction->originalAmount->asset->toString(),
            ],
            exchangeRate: $transaction->exchangeRate?->toString(),
            nature: $transaction->nature->value,
            state: $transaction->state->value,
            source: $transaction->source->value,
            bookedOn: $transaction->bookedOn->format('Y-m-d'),
            valueOn: $transaction->valueOn?->format('Y-m-d'),
            authorizedOn: $transaction->authorizedOn?->format('Y-m-d'),
            rawLabel: $transaction->rawLabel,
            counterparty: $transaction->counterparty,
            note: $transaction->note,
            paymentMethod: $transaction->paymentMethod?->value,
            mcc: $transaction->mcc,
            maskedCard: $transaction->maskedCard,
            bankReference: $transaction->bankReference,
            splits: array_map(static fn ($split): array => [
                'id' => $split->id,
                'categoryId' => $split->categoryId,
                'categoryLabel' => $categoryLabels[$split->categoryId]
                    ?? throw new \UnexpectedValueException('A transaction category label is missing.'),
                'amount' => ['value' => $split->amount->value->toString(), 'assetCode' => $split->amount->asset->toString()],
                'note' => $split->note,
            ], $transaction->splits),
            version: $transaction->version,
            createdAt: $transaction->createdAt->format(DATE_ATOM),
            updatedAt: $transaction->updatedAt->format(DATE_ATOM),
            voidedAt: $transaction->voidedAt?->format(DATE_ATOM),
        );
    }
}
