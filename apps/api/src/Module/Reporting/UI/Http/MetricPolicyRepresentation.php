<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\MetricPolicyCatalogView;
use App\Module\Reporting\Application\MetricPolicyReference;
use App\Module\Reporting\Application\MetricPolicyView;

final class MetricPolicyRepresentation
{
    /** @return array{version: ?int, label: ?string} */
    public static function reference(MetricPolicyReference $reference): array
    {
        return ['version' => $reference->version, 'label' => $reference->label];
    }

    /** @return array<string, mixed> */
    public static function policy(MetricPolicyView $policy): array
    {
        return [
            'version' => $policy->version,
            'label' => $policy->label,
            'cashExcludedAccountKinds' => $policy->cashExcludedAccountKinds,
            'savingsRateFormula' => $policy->savingsRateFormula,
            'netSavingsRateFormula' => $policy->netSavingsRateFormula,
            'createdAt' => $policy->createdAt,
            'system' => $policy->system,
        ];
    }

    /** @return array<string, mixed> */
    public static function catalog(MetricPolicyCatalogView $catalog): array
    {
        return [
            'active' => null === $catalog->active ? null : self::policy($catalog->active),
            'activeVersion' => $catalog->activeVersion,
            'activeSince' => $catalog->activeSince,
            'versions' => array_map(self::policy(...), $catalog->versions),
        ];
    }
}
