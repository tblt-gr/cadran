<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModel;

/**
 * What the audit trail is allowed to remember about a product model.
 *
 * The name is free text the holder chose and a ceiling or a rate is financial
 * data; neither travels here. Only the structural attributes a reviewer needs
 * to explain a change do, plus how many dated periods the model carries, which
 * is what makes a recorded revision visible without repeating its figures.
 *
 * @see AccountAuditFingerprint for the same rule applied to accounts
 */
final class ProductModelAuditFingerprint
{
    /** @return array<string, bool|int|string|null> */
    public static function of(ProductModel $model): array
    {
        return [
            'family' => $model->family->value,
            'nature' => $model->nature()->value,
            'wrapperKind' => $model->wrapperKind->value,
            'yieldKind' => $model->yieldKind->value,
            'valuationMode' => $model->valuationMode->value,
            'capabilities' => implode(',', $model->capabilities->toStrings()),
            'origin' => $model->provenance->origin->value,
            // A system product code is a global reference, not workspace data,
            // so it is the one provenance value a reviewer may read here.
            'basedOnProductCode' => $model->provenance->systemProductCode?->toString(),
            'basedOnModelId' => $model->provenance->sourceModelId,
            'rulePeriods' => count($model->schedule->rules),
            'archived' => $model->isArchived(),
            'version' => $model->version,
        ];
    }
}
