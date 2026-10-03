<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;

/** Identifiers, date, completeness and flags only: a price, quantity, amount or note never reaches the trail. */
final class PortfolioValuationAudit
{
    /** @param list<ValuationFlag> $flags */
    public static function recorded(Valuation $valuation, array $flags): AuditDiff
    {
        return AuditDiff::creation([...self::subject($valuation, $flags), 'supersedesId' => $valuation->supersedesId]);
    }

    /** @param list<ValuationFlag> $flags */
    public static function superseded(Valuation $superseded, string $replacementId, array $flags): AuditDiff
    {
        return AuditDiff::change(
            ['state' => 'ACTIVE'],
            [...self::subject($superseded, $flags), 'state' => 'SUPERSEDED', 'replacementId' => $replacementId],
        );
    }

    /** @param list<ValuationFlag> $flags */
    public static function withdrawn(Valuation $withdrawn, array $flags): AuditDiff
    {
        return AuditDiff::change(['state' => 'ACTIVE'], [...self::subject($withdrawn, $flags), 'state' => 'WITHDRAWN']);
    }

    /**
     * @param list<ValuationFlag> $flags
     *
     * @return array<string, string|null>
     */
    private static function subject(Valuation $valuation, array $flags): array
    {
        return [
            'portfolioId' => $valuation->accountId,
            'valuedOn' => $valuation->valuedOn->format('Y-m-d'),
            'detail' => $valuation->detail->value,
            'completeness' => $valuation->completeness->value,
            'flags' => implode(',', array_map(static fn (ValuationFlag $flag): string => $flag->value, $flags)),
        ];
    }
}
