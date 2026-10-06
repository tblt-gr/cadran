<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** The single place that decides which operations cross the portfolio boundary; later types are added here. */
final class ExternalFlow
{
    /** Deposits positive, withdrawals negative; null for an internal operation or an external one without a value. */
    public static function signedAmount(PortfolioOperation $operation): ?DecimalValue
    {
        $gross = $operation->details->grossAmount;

        return match ($operation->details->type) {
            OperationType::DEPOSIT, OperationType::PREMIUM => $gross,
            OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER => ExactDecimal::negate($operation->details->netPaidAmount() ?? DecimalValue::zero()),
            OperationType::WITHDRAWAL => null === $gross ? null : ExactDecimal::negate($gross),
            default => null,
        };
    }

    public static function linkedAmount(OperationDetails $details): ?DecimalValue
    {
        return $details->netPaidAmount() ?? $details->grossAmount;
    }

    /** Securities entering or leaving the account are external flows whose value the operation does not state. */
    public static function isUnvalued(PortfolioOperation $operation): bool
    {
        return in_array($operation->details->type, [OperationType::SECURITY_TRANSFER_IN, OperationType::SECURITY_TRANSFER_OUT], true);
    }

    public static function isExternal(PortfolioOperation $operation): bool
    {
        return null !== self::signedAmount($operation) || self::isUnvalued($operation);
    }
}
