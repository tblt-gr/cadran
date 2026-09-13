<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\Transaction;

final readonly class TransactionView
{
    /** @param list<array{id: string, categoryId: string, categoryLabel: string, categoryIcon: ?string, categoryColor: ?string, amount: array{value: string, assetCode: string}, analyticAxes: list<string>, note: ?string, categorizationOrigin: string, categorizationRuleId: ?string}> $splits */
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
        public ?string $transferId,
        public ?string $refundOriginalId,
        public ?string $refundOriginalLabel,
        /** @var array{value: string, assetCode: string}|null */
        public ?array $refundedAmount,
    ) {
    }

    /**
     * The split carries the current display identity of its category so a
     * transaction row draws the same marker as the categories screen. It is a
     * read-only projection: nothing about it is stored on the split.
     * `transferId` is a read-only projection too, resolved from the transfer
     * table rather than stored on the transaction, so the list can mark a
     * leg and link it to its transfer without a client-side heuristic.
     *
     * @param array<string, array{label: string, icon: ?string, color: ?string}> $categoryIdentities
     */
    public static function fromTransaction(
        Transaction $transaction,
        array $categoryIdentities,
        ?string $transferId,
        ?string $refundOriginalId,
        ?string $refundOriginalLabel,
        ?DecimalValue $refundedAmount,
    ): self {
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
            splits: array_map(static function ($split) use ($categoryIdentities): array {
                $identity = $categoryIdentities[$split->categoryId]
                    ?? throw new \UnexpectedValueException('A transaction category identity is missing.');

                return [
                    'id' => $split->id,
                    'categoryId' => $split->categoryId,
                    'categoryLabel' => $identity['label'],
                    'categoryIcon' => $identity['icon'],
                    'categoryColor' => $identity['color'],
                    'amount' => ['value' => $split->amount->value->toString(), 'assetCode' => $split->amount->asset->toString()],
                    'analyticAxes' => array_map(static fn ($axis): string => $axis->value, $split->analyticAxes),
                    'note' => $split->note,
                    'categorizationOrigin' => $split->origin->value,
                    'categorizationRuleId' => $split->ruleId,
                ];
            }, $transaction->splits),
            version: $transaction->version,
            createdAt: $transaction->createdAt->format(DATE_ATOM),
            updatedAt: $transaction->updatedAt->format(DATE_ATOM),
            voidedAt: $transaction->voidedAt?->format(DATE_ATOM),
            transferId: $transferId,
            refundOriginalId: $refundOriginalId,
            refundOriginalLabel: $refundOriginalLabel,
            refundedAmount: null === $refundedAmount ? null : [
                'value' => $refundedAmount->toString(), 'assetCode' => $transaction->amount->asset->toString(),
            ],
        );
    }
}
