<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Transaction;

final class CategorizationChange
{
    public static function isRequired(Transaction $transaction, CategorizationRule $winner): bool
    {
        $existing = $transaction->splits[0] ?? null;
        $targetAxes = array_map(static fn ($axis): string => $axis->value, $winner->targetAxes);
        $existingAxes = null === $existing ? [] : array_map(static fn ($axis): string => $axis->value, $existing->analyticAxes);

        return null === $existing
            || $existing->ruleId !== $winner->id
            || $existing->categoryId !== $winner->targetCategoryId
            || $existingAxes !== $targetAxes
            || (null === $transaction->counterparty && null !== $winner->targetCounterparty);
    }

    private function __construct()
    {
    }
}
