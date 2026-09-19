<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\PeriodClosingCondition;
use App\Module\Accounts\Domain\PeriodClosure;

/**
 * What the trail remembers of a closure: the month, its state and which checks
 * were waived. Never an amount or a label.
 */
final class PeriodClosureAuditFingerprint
{
    /**
     * @param array<string, string> $overrides condition => reason
     *
     * @return array<string, bool|int|string|null>
     */
    public static function of(PeriodClosure $closure, array $overrides = []): array
    {
        $fingerprint = [
            'period' => $closure->month->key(),
            'active' => $closure->isActive(),
            'version' => $closure->version,
        ];
        if (null !== $closure->reopenReason) {
            $fingerprint['reopenReason'] = $closure->reopenReason;
        }
        if ([] !== $overrides) {
            $fingerprint['overridden'] = implode(',', array_keys($overrides));
            foreach ($overrides as $condition => $reason) {
                // One key per waived check: the attribute names avoid the
                // audit redaction words, and each reason stays under the
                // per-value bound.
                $fingerprint[match ($condition) {
                    PeriodClosingCondition::UNRECONCILED_ACCOUNT->value => 'reasonUnreconciled',
                    PeriodClosingCondition::UNEXPLAINED_DISCREPANCY->value => 'reasonDiscrepancy',
                    default => 'reasonPending',
                }] = $reason;
            }
        }

        return $fingerprint;
    }
}
