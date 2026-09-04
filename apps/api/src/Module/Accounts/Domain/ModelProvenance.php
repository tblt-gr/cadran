<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Catalog\Domain\ProductCode;

/**
 * How a workspace product model came to exist, and what it was copied from.
 *
 * The reference is kept for reading, never for resolving: a duplicate stops
 * following its source the moment it is created, so a later catalogue revision
 * cannot silently change what a workspace model says. Recording where a copy
 * started is what lets a holder tell a figure they typed from a figure the
 * catalogue once published.
 */
final readonly class ModelProvenance
{
    private function __construct(
        public ProductModelOrigin $origin,
        public ?ProductCode $systemProductCode,
        public ?string $sourceModelId,
    ) {
    }

    public static function declared(): self
    {
        return new self(ProductModelOrigin::DECLARED, null, null);
    }

    public static function fromSystemProduct(ProductCode $code): self
    {
        return new self(ProductModelOrigin::SYSTEM_PRODUCT, $code, null);
    }

    public static function fromWorkspaceModel(string $modelId): self
    {
        return new self(ProductModelOrigin::WORKSPACE_MODEL, null, $modelId);
    }

    /**
     * Rebuilds a provenance read from storage, refusing a row whose origin and
     * reference disagree rather than hydrating a model that claims a source it
     * cannot name.
     */
    public static function of(ProductModelOrigin $origin, ?ProductCode $systemProductCode, ?string $sourceModelId): self
    {
        return match ($origin) {
            ProductModelOrigin::DECLARED => null === $systemProductCode && null === $sourceModelId
                ? self::declared()
                : throw new InvalidProductModel('A declared model references no source.'),
            ProductModelOrigin::SYSTEM_PRODUCT => null !== $systemProductCode && null === $sourceModelId
                ? self::fromSystemProduct($systemProductCode)
                : throw new InvalidProductModel('A model copied from the catalogue names the product code it started from.'),
            ProductModelOrigin::WORKSPACE_MODEL => null === $systemProductCode && null !== $sourceModelId
                ? self::fromWorkspaceModel($sourceModelId)
                : throw new InvalidProductModel('A model copied from another model names the model it started from.'),
        };
    }
}
