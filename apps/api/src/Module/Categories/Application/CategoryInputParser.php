<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\CategoryType;
use App\Module\Foundation\Domain\BusinessDay;
use App\Module\Foundation\Domain\InvalidBusinessDay;
use App\Module\Foundation\Domain\Uuid;

final class CategoryInputParser
{
    public static function optionalIdentifier(?string $value): ?string
    {
        if (null !== $value && !Uuid::isValid($value)) {
            throw new InvalidCategoryInput('The category parent identifier is malformed.');
        }

        return $value;
    }

    public static function type(string $value): CategoryType
    {
        try {
            return CategoryType::from($value);
        } catch (\ValueError) {
            throw new InvalidCategoryInput('The category type is not supported.');
        }
    }

    public static function lifecycleOperation(string $value): CategoryLifecycleOperation
    {
        try {
            return CategoryLifecycleOperation::from($value);
        } catch (\ValueError) {
            throw new InvalidCategoryInput('The category operation is not supported.');
        }
    }

    /**
     * Reuses the calendar day the catalogue already reads rules on, so a replacement
     * date shares the bounds and the overflow refusal of every other business date.
     */
    public static function businessDay(string $value): \DateTimeImmutable
    {
        try {
            return BusinessDay::fromIsoDate($value)->date;
        } catch (InvalidBusinessDay $exception) {
            throw new InvalidCategoryInput($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<AnalyticAxis>
     */
    public static function axes(array $values): array
    {
        try {
            return array_map(static fn (string $axis): AnalyticAxis => AnalyticAxis::from($axis), $values);
        } catch (\ValueError) {
            throw new InvalidCategoryInput('One of the analytic axes is not supported.');
        }
    }
}
