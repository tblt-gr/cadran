<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\DeclaredRuleValue;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelOrigin;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RateApplication;
use App\Module\Catalog\Domain\RateBracket;
use App\Module\Catalog\Domain\RateScale;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Foundation\Infrastructure\Persistence\DbalValue;

/**
 * Translates between a product model and the four rows sets that store it, in
 * both directions.
 *
 * The two mappings sit together on purpose: a column added to the write side
 * and forgotten on the read side is the failure this class exists to make
 * obvious. DBAL yields `mixed` for every column, so the narrowing helpers
 * assert the shape the domain expects rather than casting a surprise away.
 */
final readonly class ProductModelRow
{
    /**
     * The workspace is compared, never taken from the row: a query that leaked
     * another workspace's record must fail loudly here instead of hydrating an
     * object that looks legitimate.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $capabilities
     * @param list<ModelRule>      $rules
     */
    public static function hydrate(array $row, WorkspaceScope $workspace, array $capabilities, array $rules): ProductModel
    {
        if ($workspace->id !== DbalValue::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A product model row escaped its requested workspace.');
        }

        $sourceProduct = DbalValue::nullableText($row['derived_from_product_code'] ?? null);

        return new ProductModel(
            id: DbalValue::text($row['id'] ?? null),
            workspace: $workspace,
            name: DbalValue::text($row['name'] ?? null),
            family: AccountKind::from(DbalValue::text($row['family'] ?? null)),
            wrapperKind: WrapperKind::from(DbalValue::text($row['wrapper_kind'] ?? null)),
            yieldKind: YieldKind::from(DbalValue::text($row['yield_kind'] ?? null)),
            defaultGroupCode: DbalValue::nullableText($row['default_group_code'] ?? null),
            valuationMode: AccountValuationMode::from(DbalValue::text($row['valuation_mode'] ?? null)),
            capabilities: ProductCapabilities::fromStrings($capabilities),
            provenance: ModelProvenance::of(
                ProductModelOrigin::from(DbalValue::text($row['origin'] ?? null)),
                null === $sourceProduct ? null : ProductCode::fromString($sourceProduct),
                DbalValue::nullableText($row['derived_from_model_id'] ?? null),
            ),
            schedule: new ModelRuleSchedule($rules),
            version: (int) DbalValue::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(DbalValue::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(DbalValue::text($row['updated_at'] ?? null)),
            archivedAt: DbalValue::nullableInstant($row['archived_at'] ?? null),
        );
    }

    /**
     * One rule period and the brackets read for it. A rate carries no figure
     * of its own: its value is the scale, so an empty bracket list here is a
     * row the database constraint should already have refused.
     *
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $brackets
     */
    public static function hydrateRule(array $row, array $brackets): ModelRule
    {
        $kind = RuleKind::from(DbalValue::text($row['rule_kind'] ?? null));

        return new ModelRule(
            id: DbalValue::text($row['id'] ?? null),
            kind: $kind,
            value: match ($kind->valueType()) {
                RuleValueType::AMOUNT => DeclaredRuleValue::amount(new AssetAmount(
                    DecimalValue::fromString(DbalValue::text($row['amount_value'] ?? null)),
                    AssetCode::fromString(DbalValue::text($row['amount_asset'] ?? null)),
                )),
                RuleValueType::PERCENTAGE => DeclaredRuleValue::rate(new RateScale(
                    array_map(self::hydrateBracket(...), $brackets),
                    RateApplication::from(DbalValue::text($row['rate_application'] ?? null)),
                )),
                RuleValueType::TEXT => DeclaredRuleValue::text(DbalValue::text($row['text_value'] ?? null)),
            },
            period: new EffectivePeriod(
                validFrom: DbalValue::businessDay($row['valid_from'] ?? null),
                validTo: null === ($row['valid_to'] ?? null) ? null : DbalValue::businessDay($row['valid_to']),
            ),
        );
    }

    /**
     * The mutable columns of a model. `id`, `workspace_id`, `origin`, the two
     * provenance references and `created_at` are absent because they are
     * written once: where a model came from is not editable, and a copy that
     * could rewrite its own origin would be untraceable.
     *
     * @return array<string, mixed>
     */
    public static function columns(ProductModel $model): array
    {
        return [
            'name' => $model->name,
            'family' => $model->family->value,
            'wrapper_kind' => $model->wrapperKind->value,
            'yield_kind' => $model->yieldKind->value,
            'default_group_code' => $model->defaultGroupCode,
            'valuation_mode' => $model->valuationMode->value,
            'version' => $model->version,
            'updated_at' => $model->updatedAt->format('Y-m-d H:i:s.uP'),
            'archived_at' => $model->archivedAt?->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ruleColumns(ModelRule $rule, \DateTimeImmutable $recordedAt): array
    {
        $scale = $rule->value->scale;
        $amount = $rule->value->amount;

        return [
            'id' => $rule->id,
            'rule_kind' => $rule->kind->value,
            'amount_value' => $amount?->value->toString(),
            'amount_asset' => $amount?->asset->toString(),
            'text_value' => $rule->value->text,
            'rate_application' => $scale?->application->value,
            'valid_from' => $rule->period->validFrom->format('Y-m-d'),
            'valid_to' => $rule->period->validTo?->format('Y-m-d'),
            'created_at' => $recordedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateBracket(array $row): RateBracket
    {
        $upperBound = DbalValue::nullableText($row['upper_bound'] ?? null);

        return new RateBracket(
            DecimalValue::fromString(DbalValue::text($row['lower_bound'] ?? null)),
            null === $upperBound ? null : DecimalValue::fromString($upperBound),
            DecimalValue::fromString(DbalValue::text($row['percentage'] ?? null)),
        );
    }
}
