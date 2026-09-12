<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\RefundableView;

final readonly class RefundRepresentation
{
    /** @return array<string, mixed> */
    public static function refundable(RefundableView $view): array
    {
        return [
            'originalId' => $view->originalId,
            'originalAmount' => $view->originalAmount,
            'refunded' => $view->refunded,
            'refundable' => $view->refundable,
            'proposedSplits' => $view->proposedSplits,
        ];
    }
}
