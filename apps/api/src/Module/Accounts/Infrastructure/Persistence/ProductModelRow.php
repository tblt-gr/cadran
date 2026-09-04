<?php

declare(strict_types=1);

namespace App\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ModelRuleValue;
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
        if ($workspace->id !== self::text($row['workspace_id'] ?? null)) {
            throw new \UnexpectedValueException('A product model row escaped its requested workspace.');
        }

        $sourceProduct = self::nullableText($row['derived_from_product_code'] ?? null);

        return new ProductModel(
            id: self::text($row['id'] ?? null),
            workspace: $workspace,
            name: self::text($row['name'] ?? null),
            family: AccountKind::from(self::text($row['family'] ?? null)),
            wrapperKind: WrapperKind::from(self::text($row['wrapper_kind'] ?? null)),
            yieldKind: YieldKind::from(self::text($row['yield_kind'] ?? null)),
            defaultGroupCode: self::nullableText($row['default_group_code'] ?? null),
            valuationMode: AccountValuationMode::from(self::text($row['valuation_mode'] ?? null)),
            capabilities: ProductCapabilities::fromStrings($capabilities),
            provenance: ModelProvenance::of(
                ProductModelOrigin::from(self::text($row['origin'] ?? null)),
                null === $sourceProduct ? null : ProductCode::fromString($sourceProduct),
                self::nullableText($row['derived_from_model_id'] ?? null),
            ),
            schedule: new ModelRuleSchedule($rules),
            version: (int) self::text($row['version'] ?? null),
            createdAt: new \DateTimeImmutable(self::text($row['created_at'] ?? null)),
            updatedAt: new \DateTimeImmutable(self::text($row['updated_at'] ?? null)),
            archivedAt: self::instant($row['archived_at'] ?? null),
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
        $kind = RuleKind::from(self::text($row['rule_kind'] ?? null));

        return new ModelRule(
            id: self::text($row['id'] ?? null),
            kind: $kind,
            value: match ($kind->valueType()) {
                RuleValueType::AMOUNT => ModelRuleValue::amount(new AssetAmount(
                    DecimalValue::fromString(self::text($row['amount_value'] ?? null)),
                    AssetCode::fromString(self::text($row['amount_asset'] ?? null)),
                )),
                RuleValueType::PERCENTAGE => ModelRuleValue::rate(new RateScale(
                    array_map(self::hydrateBracket(...), $brackets),
                    RateApplication::from(self::text($row['rate_application'] ?? null)),
                )),
                RuleValueType::TEXT => ModelRuleValue::text(self::text($row['text_value'] ?? null)),
            },
            period: new EffectivePeriod(
                validFrom: self::day($row['valid_from'] ?? null),
                validTo: null === ($row['valid_to'] ?? null) ? null : self::day($row['valid_to']),
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
        $upperBound = self::nullableText($row['upper_bound'] ?? null);

        return new RateBracket(
            DecimalValue::fromString(self::text($row['lower_bound'] ?? null)),
            null === $upperBound ? null : DecimalValue::fromString($upperBound),
            DecimalValue::fromString(self::text($row['percentage'] ?? null)),
        );
    }

    public static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return null === $value ? null : self::text($value);
    }

    /**
     * A `DATE` column carries no time and no zone; the domain reads it as the
     * UTC calendar day it is, rather than as midnight in the server timezone.
     */
    private static function day(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::text($value), new \DateTimeZone('UTC'));
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable(self::text($value));
    }
}
