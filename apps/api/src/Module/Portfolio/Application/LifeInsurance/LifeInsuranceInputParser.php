<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;

/** Turns the literals of a life-insurance request into dates and exact decimals, with the matching refusal code. */
final class LifeInsuranceInputParser
{
    public static function day(string $literal, string $code): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidLifeInsurance('A date is a real ISO 8601 calendar day.', $code);
        }

        return $date;
    }

    public static function decimal(?string $literal, string $code): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        try {
            return DecimalValue::fromString($literal);
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw new InvalidLifeInsurance('A rate is a canonical decimal string.', $code);
        }
    }

    public static function requiredDecimal(string $literal, string $code): DecimalValue
    {
        return self::decimal($literal, $code) ?? throw new InvalidLifeInsurance('A rate is required.', $code);
    }
}
