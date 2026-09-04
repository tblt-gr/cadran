<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ProductModelOrigin;
use App\Module\Catalog\Domain\ProductCode;
use PHPUnit\Framework\TestCase;

/**
 * A model that claims a source must be able to name it. A half-recorded
 * provenance would show a copy as catalogue-backed while nothing says what it
 * was copied from.
 */
final class ModelProvenanceTest extends TestCase
{
    public function testADeclaredModelReferencesNothing(): void
    {
        $provenance = ModelProvenance::declared();

        self::assertSame(ProductModelOrigin::DECLARED, $provenance->origin);
        self::assertNull($provenance->systemProductCode);
        self::assertNull($provenance->sourceModelId);
    }

    public function testACopyOfACatalogueProductNamesTheCode(): void
    {
        $provenance = ModelProvenance::fromSystemProduct(ProductCode::fromString('FR_LIVRET_A'));

        self::assertSame(ProductModelOrigin::SYSTEM_PRODUCT, $provenance->origin);
        self::assertSame('FR_LIVRET_A', $provenance->systemProductCode?->toString());
    }

    public function testARowClaimingACatalogueOriginWithoutACodeIsRefused(): void
    {
        $this->expectException(InvalidProductModel::class);

        ModelProvenance::of(ProductModelOrigin::SYSTEM_PRODUCT, null, null);
    }

    public function testARowClaimingNoOriginWhileNamingASourceIsRefused(): void
    {
        $this->expectException(InvalidProductModel::class);

        ModelProvenance::of(
            ProductModelOrigin::DECLARED,
            ProductCode::fromString('FR_LIVRET_A'),
            null,
        );
    }

    public function testARowClaimingAModelOriginWithoutAModelIsRefused(): void
    {
        $this->expectException(InvalidProductModel::class);

        ModelProvenance::of(ProductModelOrigin::WORKSPACE_MODEL, null, null);
    }
}
