<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ProductModelPage;
use App\Module\Accounts\Application\ProductModelRateBracketView;
use App\Module\Accounts\Application\ProductModelRuleView;
use App\Module\Accounts\Application\ProductModelView;

/**
 * The wire shape of a workspace product model.
 *
 * Every decimal leaves as a canonical string: a JSON number would hand the
 * client a binary float, and a rate is not a figure a client may round on the
 * way in. A period with a null `validTo` is in force with no known end, which
 * a client renders as still applying rather than as a missing value.
 */
final readonly class ProductModelRepresentation
{
    /** @return array<string, mixed> */
    public static function page(ProductModelPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }

    /** @return array<string, mixed> */
    public static function one(ProductModelView $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'family' => $model->family,
            // Stated by the server so no interface deduces from a family name
            // which side of the balance sheet a model sits on.
            'nature' => $model->nature,
            'wrapperKind' => $model->wrapperKind,
            'yieldKind' => $model->yieldKind,
            // Also stated by the server: a model whose yield is revisable or
            // market-driven never shows a rate as an acquired return.
            'yieldGuaranteed' => $model->yieldGuaranteed,
            'ceilingBasis' => $model->ceilingBasis,
            'defaultGroupCode' => $model->defaultGroupCode,
            'valuationMode' => $model->valuationMode,
            // The capability list is the public activation contract. Clients
            // never infer behaviour from a model name the holder chose.
            'capabilities' => $model->capabilities,
            'origin' => $model->origin,
            'basedOnProductCode' => $model->basedOnProductCode,
            'basedOnModelId' => $model->basedOnModelId,
            'rules' => array_map(self::rule(...), $model->rules),
            'editable' => $model->editable,
            'version' => $model->version,
            'createdAt' => $model->createdAt,
            'updatedAt' => $model->updatedAt,
            'archivedAt' => $model->archivedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function rule(ProductModelRuleView $rule): array
    {
        return [
            'id' => $rule->id,
            'kind' => $rule->kind,
            'valueType' => $rule->valueType,
            'amount' => null === $rule->amount ? null : [
                'value' => $rule->amount,
                'assetCode' => $rule->amountAssetCode,
            ],
            'text' => $rule->text,
            'rateApplication' => $rule->rateApplication,
            'brackets' => array_map(self::bracket(...), $rule->brackets),
            'validFrom' => $rule->validFrom,
            // An open end is in force for every later date, not an expiry.
            'validTo' => $rule->validTo,
        ];
    }

    /** @return array<string, mixed> */
    private static function bracket(ProductModelRateBracketView $bracket): array
    {
        return [
            'lowerBound' => $bracket->lowerBound,
            'upperBound' => $bracket->upperBound,
            // Expressed in percent as the holder entered it: `4` reads 4 %, as
            // a canonical string so no binary float touches a rate.
            'percentage' => $bracket->percentage,
        ];
    }
}
