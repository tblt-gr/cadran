<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\ExactDecimal;

/** Exact components of one complete observation; an inconsistent split cannot be published. */
final class ContractAllocation
{
    /** @return list<array{kind: string, value: string, share: ?string}> */
    public static function of(string $total, string $cash, string $euroFund, string $unitLinked): array
    {
        $sum = ExactDecimal::addForResponse($cash, ExactDecimal::addForResponse($euroFund, $unitLinked));
        if (0 !== ExactDecimal::compareForResponse($sum, $total)) {
            throw new \LogicException('The contract allocation must equal its observed valuation.');
        }
        $items = [];
        foreach (['EURO_FUND' => $euroFund, 'UNIT_LINKED' => $unitLinked, 'CASH' => $cash] as $kind => $value) {
            $items[] = ['kind' => $kind, 'value' => $value, 'share' => self::share($value, $total)];
        }

        return $items;
    }

    public static function share(string $value, string $total): ?string
    {
        return ExactDecimal::compareForResponse($total, '0') <= 0 ? null : ExactDecimal::divideForResponse($value, $total, 6);
    }
}
