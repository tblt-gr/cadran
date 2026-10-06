<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Portfolio\Domain\OperationLeg;

final class OperationLegView
{
    /** @param array<string, mixed>|null $instrument
     * @return array<string, mixed>
     */
    public static function of(OperationLeg $leg, string $asset, ?array $instrument = null): array
    {
        $amount = static fn (string $value): array => ['value' => $value, 'assetCode' => $asset];

        return ['index' => $leg->index, 'direction' => $leg->direction->value, 'instrument' => $instrument,
            'quantity' => $leg->quantity->toString(), 'unitPrice' => $leg->unitPrice->toString(),
            'amount' => $amount($leg->amount->toString()),
            'roundingResidual' => null === $leg->roundingResidual ? null : $amount($leg->roundingResidual->toString())];
    }
}
