<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Transactions\Domain\Transaction;

/** Refund amounts and allocations remain the ones accepted against the original expense. */
final class RefundEditPolicy
{
    public static function assertUnchanged(Transaction $current, TransactionDraft $draft, UpdateTransactionInput $input, TransactionSplitInputParser $parser): void
    {
        foreach (['nature', 'state', 'valueOn', 'authorizedOn', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference'] as $field) {
            if ($draft->$field != $current->$field) {
                throw new InvalidTransactionInput('Only refund account, booked date, label, counterparty and note can change.');
            }
        }
        if ($draft->amount != $current->amount) {
            throw new InvalidTransactionInput('A refund amount cannot change.');
        }
        if (null === $input->splits) {
            if (count($current->splits) > 1 || $input->categoryId !== ($current->splits[0]->categoryId ?? null)) {
                throw new InvalidTransactionInput('A refund allocation cannot change.');
            }

            return;
        }
        $splits = $parser->parse($input->splits);
        if (null !== $input->categoryId || count($splits) !== count($current->splits)) {
            throw new InvalidTransactionInput('A refund allocation cannot change.');
        }
        foreach ($splits as $index => $split) {
            $saved = $current->splits[$index];
            if ($split->categoryId !== $saved->categoryId || $split->amount != $saved->amount
                || $split->analyticAxes !== $saved->analyticAxes || $split->note !== $saved->note) {
                throw new InvalidTransactionInput('A refund allocation cannot change.');
            }
        }
    }
}
