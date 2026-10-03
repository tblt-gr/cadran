<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Portfolio\Domain\PortfolioOperation;

/** Names and identifiers only: an amount, quantity, price, reference, note or reason never reaches the trail. */
final class PortfolioOperationAudit
{
    public static function recorded(PortfolioOperation $operation, ?string $originalOperationId = null): AuditDiff
    {
        return AuditDiff::creation([...self::subject($operation), 'originalOperationId' => $originalOperationId]);
    }

    public static function voided(PortfolioOperation $voided): AuditDiff
    {
        return AuditDiff::change(['state' => 'ACTIVE'], [...self::subject($voided), 'state' => 'VOIDED']);
    }

    public static function replaced(PortfolioOperation $voided, string $replacementOperationId): AuditDiff
    {
        return AuditDiff::change(['state' => 'ACTIVE'], [
            ...self::subject($voided), 'state' => 'VOIDED', 'voidKind' => 'REPLACED', 'replacementOperationId' => $replacementOperationId,
        ]);
    }

    /** @param list<string> $operationIds */
    public static function reordered(string $accountId, array $operationIds): AuditDiff
    {
        return AuditDiff::change([], ['portfolioId' => $accountId, 'operationIds' => implode(',', $operationIds)]);
    }

    /** @return array<string, string|null> */
    private static function subject(PortfolioOperation $operation): array
    {
        return [
            'operationType' => $operation->details->type->value,
            'portfolioId' => $operation->accountId,
            'instrumentId' => $operation->details->instrumentId,
            'counterInstrumentId' => $operation->details->counterInstrumentId,
        ];
    }
}
