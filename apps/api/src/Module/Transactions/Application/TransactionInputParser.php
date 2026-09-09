<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Transactions\Domain\PaymentMethod;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionState;

final readonly class TransactionInputParser
{
    public function __construct(private AmountInputParser $amountParser)
    {
    }

    public function parse(
        mixed $amount,
        string $nature,
        string $state,
        string $bookedOn,
        ?string $valueOn,
        ?string $authorizedOn,
        string $rawLabel,
        ?string $counterparty,
        ?string $note,
        ?string $paymentMethod,
        ?string $mcc,
        ?string $maskedCard,
        ?string $bankReference,
    ): TransactionDraft {
        try {
            $parsedNature = TransactionNature::from($nature);
            if (in_array($parsedNature, [TransactionNature::TRANSFER, TransactionNature::REFUND], true)) {
                throw new \DomainException('Linked transaction natures require their dedicated use case.');
            }

            return new TransactionDraft(
                amount: ($this->amountParser)($amount, '/amount'),
                nature: $parsedNature,
                state: TransactionState::from($state),
                bookedOn: BusinessDay::fromIsoDate($bookedOn)->date,
                valueOn: $this->optionalDay($valueOn),
                authorizedOn: $this->optionalDay($authorizedOn),
                rawLabel: $rawLabel,
                counterparty: $counterparty,
                note: $note,
                paymentMethod: null === $paymentMethod ? null : PaymentMethod::from($paymentMethod),
                mcc: $mcc,
                maskedCard: $maskedCard,
                bankReference: $bankReference,
            );
        } catch (\ValueError|\DomainException|\InvalidArgumentException $exception) {
            throw new InvalidTransactionInput('The transaction input is invalid.', previous: $exception);
        }
    }

    private function optionalDay(?string $day): ?\DateTimeImmutable
    {
        return null === $day ? null : BusinessDay::fromIsoDate($day)->date;
    }
}
