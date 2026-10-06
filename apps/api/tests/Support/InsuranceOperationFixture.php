<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\LegDirection;
use App\Module\Portfolio\Domain\OperationCashEffect;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationLeg;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;

final class InsuranceOperationFixture
{
    public const string FUND = '00000000-0000-7000-8000-000000000b03';
    public const string EQUITY = PortfolioFixture::ETF;

    public static function leg(int $index, LegDirection $direction, string $instrument, string $quantity, string $price, string $amount): OperationLeg
    {
        return new OperationLeg($index, $direction, $instrument, DecimalValue::fromString($quantity), DecimalValue::fromString($price), DecimalValue::fromString($amount), 6, 4, 2);
    }

    /** @param list<OperationLeg> $legs */
    public static function operation(OperationType $type, string $gross, array $legs = [], string $fees = '0.00', ?string $incomeTax = null, ?string $socialTax = null, int $sequence = 1, string $date = '2026-01-15'): PortfolioOperation
    {
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, null, null, null, null,
            DecimalValue::fromString($gross), DecimalValue::fromString($fees), null, null, null, null, null, null, null, null, null,
            $legs, null === $incomeTax ? null : DecimalValue::fromString($incomeTax), null === $socialTax ? null : DecimalValue::fromString($socialTax),
        );
        $effect = OperationCashEffect::derive($type, null, null, $details->grossAmount, $details->fees ?? DecimalValue::zero(), $details->taxes ?? DecimalValue::zero(), 2, $legs);

        return new PortfolioOperation(sprintf('00000000-0000-7000-8000-%012d', $sequence), WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, $effect->cashAmount, null, OperationState::ACTIVE, null, null, null, null, null,
            new \DateTimeImmutable('2026-01-15'), WorkspaceFixture::OWNER_ID, 1);
    }

    public static function premium(): PortfolioOperation
    {
        return self::operation(OperationType::PREMIUM, '10000.00', [
            self::leg(1, LegDirection::IN, self::FUND, '6000.00', '1', '6000.00'),
            self::leg(2, LegDirection::IN, self::EQUITY, '40', '97.50', '3900.00'),
        ], '100.00');
    }

    public static function surrender(): PortfolioOperation
    {
        return self::operation(OperationType::PARTIAL_SURRENDER, '1050.00', [
            self::leg(1, LegDirection::OUT, self::EQUITY, '10', '105.00', '1050.00'),
        ], incomeTax: '7.50', socialTax: '8.60', sequence: 2, date: '2026-06-10');
    }
}
