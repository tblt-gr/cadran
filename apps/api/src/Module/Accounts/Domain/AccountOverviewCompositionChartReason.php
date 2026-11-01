<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\ExactDecimal;

/**
 * Why a pie of the whole net worth would mislead. The account table stays
 * available in every case.
 */
enum AccountOverviewCompositionChartReason: string
{
    case MISSING_VALUATION = 'MISSING_VALUATION';
    case MIXED_ASSETS = 'MIXED_ASSETS';
    case ZERO_TOTAL = 'ZERO_TOTAL';
    case NEGATIVE_TOTAL = 'NEGATIVE_TOTAL';
    case NEGATIVE_COMPONENT = 'NEGATIVE_COMPONENT';

    /**
     * Checks in the order of the cases above, so the most fundamental defect
     * is the one reported.
     *
     * @param list<AccountShareInput> $eligible every eligible account of the day
     */
    public static function of(array $eligible): ?self
    {
        $assets = [];
        foreach ($eligible as $input) {
            if (null === $input->value) {
                return self::MISSING_VALUATION;
            }
            if (null !== $input->asset) {
                $assets[$input->asset->toString()] = true;
            }
        }
        if (count($assets) > 1) {
            return self::MIXED_ASSETS;
        }

        $signed = array_map(
            static fn (AccountShareInput $input) => ExactDecimal::signed($input->value ?? throw new \LogicException('Checked above.'), $input->netWorthSign),
            $eligible,
        );
        if ([] === $signed) {
            return null;
        }

        $total = ExactDecimal::sum(...$signed);
        if (ExactDecimal::isZero($total)) {
            return self::ZERO_TOTAL;
        }
        if ($total->isNegative()) {
            return self::NEGATIVE_TOTAL;
        }
        foreach ($signed as $value) {
            if ($value->isNegative()) {
                return self::NEGATIVE_COMPONENT;
            }
        }

        return null;
    }
}
