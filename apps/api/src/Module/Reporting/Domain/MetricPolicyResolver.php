<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

/**
 * Which policy version governs a month: a closed month keeps the version active
 * at its closing instant, an open month the version active now. Without any
 * activation at that instant the built-in version 1 applies.
 */
final class MetricPolicyResolver
{
    /** @param list<MetricPolicyActivation> $activations */
    public static function forMonth(?\DateTimeImmutable $closedAt, \DateTimeImmutable $now, array $activations): int
    {
        $instant = $closedAt ?? $now;
        $version = MetricPolicy::SYSTEM_VERSION;
        $latest = null;
        foreach ($activations as $activation) {
            if ($activation->activeFrom > $instant) {
                continue;
            }
            if (null === $latest || $activation->activeFrom > $latest) {
                $latest = $activation->activeFrom;
                $version = $activation->policyVersion;
            }
        }

        return $version;
    }
}
