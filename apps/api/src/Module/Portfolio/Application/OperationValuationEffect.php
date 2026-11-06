<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationType;

/**
 * What an operation restates in the valuation of the day: which types a refresh follows, the net change of quantity
 * per instrument and the price of the purchase. Every other type keeps its behaviour and refreshes nothing.
 */
final class OperationValuationEffect
{
    private const array FOLLOWED = [
        OperationType::BUY, OperationType::SELL, OperationType::DEPOSIT, OperationType::WITHDRAWAL,
        OperationType::DIVIDEND, OperationType::INTEREST, OperationType::FEE, OperationType::TAX,
    ];

    public static function followed(OperationDetails $details): bool
    {
        return in_array($details->type, self::FOLLOWED, true);
    }

    /**
     * @param int $sign 1 when the operation is applied, -1 when it is voided or replaced
     *
     * @return array<string, DecimalValue> instrument identifier => net change of quantity
     */
    public static function quantityChanges(OperationDetails $details, int $sign = 1): array
    {
        if (null === $details->instrumentId || null === $details->quantity) {
            return [];
        }
        $effect = match ($details->type) {
            OperationType::BUY => 1,
            OperationType::SELL => -1,
            default => 0,
        };

        return 0 === $effect ? [] : [$details->instrumentId => ExactDecimal::signed($details->quantity, $effect * $sign)];
    }

    /**
     * The replacement minus the original, summed per instrument, the zeros dropped.
     *
     * @param array<string, DecimalValue> $applied
     * @param array<string, DecimalValue> $removed
     *
     * @return array<string, DecimalValue>
     */
    public static function net(array $applied, array $removed): array
    {
        $net = $applied;
        foreach ($removed as $instrumentId => $change) {
            $net[$instrumentId] = isset($net[$instrumentId]) ? ExactDecimal::add($net[$instrumentId], $change) : $change;
        }

        return array_filter($net, static fn (DecimalValue $change): bool => !ExactDecimal::isZero($change));
    }

    public static function bought(OperationDetails $details): ?OperationPrice
    {
        if (OperationType::BUY !== $details->type || null === $details->instrumentId || null === $details->unitPrice) {
            return null;
        }

        return new OperationPrice($details->instrumentId, $details->unitPrice, $details->tradeDate);
    }
}
