<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidDeclaredRule;
use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\WrapperKind;
use App\Module\Catalog\Domain\YieldKind;

/**
 * Turns the strings a client submits for a product model into the closed types
 * the domain accepts.
 *
 * What a model says about one dated rule is parsed by
 * {@see DeclaredRuleParser}, which an account override submits to as well: the
 * two state the same thing about different scopes and must refuse the same
 * malformed values. What remains here is the description around those periods
 * — the family, the envelope, the yield, the capabilities — which belongs to a
 * model and to nothing else.
 *
 * Every unknown token is refused rather than defaulted.
 */
final class ProductModelInputParser
{
    public const int MAX_BRACKETS = DeclaredRuleParser::MAX_BRACKETS;
    public const int MAX_RULES_PER_REQUEST = 20;

    public static function family(string $value): AccountKind
    {
        return AccountKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model family must be a supported account kind.');
    }

    public static function wrapperKind(string $value): WrapperKind
    {
        return WrapperKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model envelope must be a supported wrapper kind.');
    }

    public static function yieldKind(string $value): YieldKind
    {
        return YieldKind::tryFrom($value) ?? throw new InvalidProductModelInput('The model yield must be a supported yield kind.');
    }

    public static function valuationMode(string $value): AccountValuationMode
    {
        return AccountValuationMode::tryFrom($value) ?? throw new InvalidProductModelInput('The model valuation mode must be a supported mode.');
    }

    /**
     * @param list<string> $values
     */
    public static function capabilities(array $values): ProductCapabilities
    {
        try {
            return ProductCapabilities::fromStrings($values);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }

    public static function rule(DeclaredRuleInput $input, string $id): ModelRule
    {
        try {
            $kind = DeclaredRuleParser::kind($input->kind);

            return new ModelRule(
                id: $id,
                kind: $kind,
                value: DeclaredRuleParser::value($kind, $input),
                period: DeclaredRuleParser::period($input),
            );
        } catch (InvalidProductModel|InvalidDeclaredRule|InvalidDeclaredRuleInput $failure) {
            throw new InvalidProductModelInput($failure->getMessage(), previous: $failure);
        }
    }
}
