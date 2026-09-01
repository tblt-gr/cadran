<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Categories\Domain\CategoryType;

final class CategoryInputParser
{
    public static function optionalIdentifier(?string $value): ?string
    {
        if (null !== $value && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
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
