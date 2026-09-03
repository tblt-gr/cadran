<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\MaskedIdentifier;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetCode;

/**
 * Turns the strings of a request into the closed types the domain accepts.
 *
 * Every failure here is an {@see InvalidAccountInput}: a submitted value the
 * contract does not name is a client error, never a domain exception escaping
 * to the controller.
 */
final class AccountInputParser
{
    public static function kind(string $value): AccountKind
    {
        return AccountKind::tryFrom($value)
            ?? throw new InvalidAccountInput('The account kind is not supported.');
    }

    public static function valuationMode(string $value): AccountValuationMode
    {
        return AccountValuationMode::tryFrom($value)
            ?? throw new InvalidAccountInput('The account valuation mode is not supported.');
    }

    public static function liquidityLevel(string $value): LiquidityLevel
    {
        return LiquidityLevel::tryFrom($value)
            ?? throw new InvalidAccountInput('The account liquidity level is not supported.');
    }

    public static function assetCode(string $value): AssetCode
    {
        try {
            return AssetCode::fromString($value);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidAccountInput('The account asset code is malformed.', previous: $exception);
        }
    }

    public static function maskedIdentifier(?string $value): ?MaskedIdentifier
    {
        if (null === $value) {
            return null;
        }

        try {
            return MaskedIdentifier::fromString($value);
        } catch (InvalidAccount $exception) {
            throw new InvalidAccountInput($exception->getMessage(), previous: $exception);
        }
    }

    public static function optionalBusinessDay(?string $value, string $field): ?\DateTimeImmutable
    {
        return null === $value ? null : self::businessDay($value, $field);
    }

    /**
     * A lifecycle date is a calendar day, not an instant: the day is read in
     * UTC and rejected unless the submitted string is exactly that day, so a
     * client cannot smuggle a timezone shift into an opening date.
     */
    public static function businessDay(string $value, string $field): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidAccountInput(sprintf('The account %s must be an ISO 8601 calendar day.', $field));
        }

        return $date;
    }
}
