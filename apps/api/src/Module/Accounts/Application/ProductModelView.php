<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\ProductModel;

/**
 * A workspace product model as a client reads it.
 *
 * The derived judgements are stated by the server rather than left to a
 * screen: whether the yield is owed to the holder, which measure a ceiling is
 * read against, and which side of the balance sheet the model sits on. A
 * client that inferred any of the three from a family name would eventually
 * infer it differently from the domain.
 */
final readonly class ProductModelView
{
    /**
     * @param list<string>               $capabilities
     * @param list<ProductModelRuleView> $rules
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $family,
        public string $nature,
        public string $wrapperKind,
        public string $yieldKind,
        public bool $yieldGuaranteed,
        public string $ceilingBasis,
        public ?string $defaultGroupCode,
        public string $valuationMode,
        public array $capabilities,
        public string $origin,
        public ?string $basedOnProductCode,
        public ?string $basedOnModelId,
        public array $rules,
        public bool $editable,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
    ) {
    }

    public static function fromModel(ProductModel $model): self
    {
        return new self(
            id: $model->id,
            name: $model->name,
            family: $model->family->value,
            nature: $model->nature()->value,
            wrapperKind: $model->wrapperKind->value,
            yieldKind: $model->yieldKind->value,
            // A model whose yield is not owed to the holder never shows a rate
            // as an acquired return, whatever periods it carries.
            yieldGuaranteed: $model->yieldKind->isGuaranteed(),
            ceilingBasis: $model->ceilingBasis()->value,
            defaultGroupCode: $model->defaultGroupCode,
            valuationMode: $model->valuationMode->value,
            capabilities: $model->capabilities->toStrings(),
            origin: $model->provenance->origin->value,
            basedOnProductCode: $model->provenance->systemProductCode?->toString(),
            basedOnModelId: $model->provenance->sourceModelId,
            rules: array_map(ProductModelRuleView::of(...), $model->schedule->rules),
            editable: !$model->isArchived(),
            version: $model->version,
            createdAt: $model->createdAt->format(DATE_ATOM),
            updatedAt: $model->updatedAt->format(DATE_ATOM),
            archivedAt: $model->archivedAt?->format(DATE_ATOM),
        );
    }
}
