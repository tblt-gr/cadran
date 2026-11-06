<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;

/**
 * Identifiers, date and counts only: a quantity, price, amount or instrument name never reaches the trail. The
 * identifiers of the operations are bounded by the audit value length; every operation also has its own event.
 */
final class PortfolioTakeoverAudit
{
    public const string ENTITY = 'portfolio_takeover';
    public const string RECORDED = 'portfolio_takeover.recorded';
    private const int LISTED_OPERATIONS = 6;

    /** @param list<string> $operationIds */
    public static function recorded(PortfolioTakeover $takeover, int $lineCount, array $operationIds): AuditDiff
    {
        return AuditDiff::creation([
            'portfolioId' => $takeover->accountId,
            'takenOverOn' => $takeover->takenOverOn->format('Y-m-d'),
            'lineCount' => (string) $lineCount,
            'valuationId' => $takeover->valuationId,
            'operationCount' => (string) count($operationIds),
            'operationIds' => implode(',', array_slice($operationIds, 0, self::LISTED_OPERATIONS)),
        ]);
    }
}
