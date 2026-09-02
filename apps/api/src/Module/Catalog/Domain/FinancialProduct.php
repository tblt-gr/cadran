<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * A read-only, system-scoped product model: what a Livret A or a PEA is,
 * independently of anyone's account.
 *
 * The catalogue holds no workspace data, so this carries no `workspace_id`.
 * Its rows change through a reviewed migration, never through a request.
 */
final readonly class FinancialProduct
{
    public const int MAX_DISPLAY_NAME_LENGTH = 80;
    public const int MAX_GROUP_CODE_LENGTH = 32;

    public function __construct(
        public ProductCode $code,
        public string $displayName,
        public ?string $jurisdiction,
        public AccountKind $accountKind,
        public WrapperKind $wrapperKind,
        public YieldKind $yieldKind,
        public ?string $defaultGroupCode,
        public ProductCapabilities $capabilities,
        public int $catalogVersion,
        public ?\DateTimeImmutable $archivedAt = null,
    ) {
        if ($displayName !== trim($displayName) || '' === $displayName || mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new InvalidCatalogEntry(sprintf('A product name contains between 1 and %d characters.', self::MAX_DISPLAY_NAME_LENGTH));
        }

        // A product without a jurisdiction is deliberately allowed: a generic
        // model is not French, and pretending otherwise would attach it the
        // wrong regulatory rules.
        if (null !== $jurisdiction && 1 !== preg_match('/^[A-Z]{2}$/D', $jurisdiction)) {
            throw new InvalidCatalogEntry('A product jurisdiction is an ISO 3166-1 alpha-2 country code.');
        }

        if (null !== $defaultGroupCode && 1 !== preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', $defaultGroupCode)) {
            throw new InvalidCatalogEntry('A default group code is an uppercase token.');
        }

        if (null !== $defaultGroupCode && strlen($defaultGroupCode) > self::MAX_GROUP_CODE_LENGTH) {
            throw new InvalidCatalogEntry(sprintf('A default group code is at most %d characters.', self::MAX_GROUP_CODE_LENGTH));
        }

        if ($catalogVersion < 1) {
            throw new InvalidCatalogEntry('A catalogue version is positive.');
        }

        $supportsLiability = $capabilities->contains(ProductCapability::SUPPORTS_LIABILITY);
        if (AccountKind::LIABILITY === $accountKind && !$supportsLiability) {
            throw new InvalidCatalogEntry('A LIABILITY product requires SUPPORTS_LIABILITY.');
        }

        if (AccountKind::LIABILITY !== $accountKind && $supportsLiability) {
            throw new InvalidCatalogEntry('SUPPORTS_LIABILITY is exclusive to LIABILITY products.');
        }
    }
}
