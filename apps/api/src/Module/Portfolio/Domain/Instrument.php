<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;

final readonly class Instrument
{
    public const int MAX_NAME_LENGTH = 120;
    public const int MAX_PRECISION = 12;

    public string $name;
    public string $normalizedName;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        string $name,
        public ?Isin $isin,
        public ?Ticker $ticker,
        public ?VenueMic $venueMic,
        public InstrumentType $instrumentType,
        public ?AssetClass $assetClass,
        public ?InstrumentRegion $region,
        public AssetCode $currencyCode,
        public int $quantityPrecision,
        public int $pricePrecision,
        public InstrumentSource $source,
        public ?\DateTimeImmutable $reviewedAt,
        public ?string $reviewedBy,
        public InstrumentStatus $status,
        public ?\DateTimeImmutable $usedAt,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public \DateTimeImmutable $updatedAt,
        public int $version,
    ) {
        self::assertIdentifier($id);
        self::assertIdentifier($createdBy);
        if (null !== $reviewedBy) {
            self::assertIdentifier($reviewedBy);
        }
        if ((null === $reviewedAt) !== (null === $reviewedBy)) {
            throw new InvalidInstrument('Review time and reviewer must be present together.', 'invalid_instrument_review');
        }
        if (null === $isin && null === $ticker) {
            throw new InvalidInstrument('An instrument identifier is required.', 'instrument_identifier_required');
        }
        self::assertPrecision($quantityPrecision);
        self::assertPrecision($pricePrecision);
        if ($version < 1) {
            throw new InvalidInstrument('An instrument version must be positive.', 'invalid_instrument_version');
        }

        $this->name = self::normalizeDisplayName($name);
        $this->normalizedName = self::normalizeName($this->name);
        // Lowercasing can lengthen a string (e.g. "İ" becomes two code points), so
        // the stored search key needs its own bound.
        if (mb_strlen($this->normalizedName) > self::MAX_NAME_LENGTH) {
            throw new InvalidInstrument('An instrument name must be plain text between 1 and 120 characters.', 'invalid_instrument_name');
        }
    }

    public static function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/[\p{Z}\s]+/u', ' ', self::trimUnicodeWhitespace($name));

        return mb_strtolower($collapsed ?? self::trimUnicodeWhitespace($name));
    }

    public function editManually(
        string $name,
        ?Isin $isin,
        ?Ticker $ticker,
        ?VenueMic $venueMic,
        InstrumentType $instrumentType,
        ?AssetClass $assetClass,
        ?InstrumentRegion $region,
        AssetCode $currencyCode,
        int $quantityPrecision,
        int $pricePrecision,
        string $actorId,
        \DateTimeImmutable $at,
    ): self {
        if (null !== $this->usedAt && (
            !self::sameNullable($this->isin, $isin)
            || !$this->currencyCode->equals($currencyCode)
            || $this->quantityPrecision !== $quantityPrecision
            || $this->pricePrecision !== $pricePrecision
        )) {
            throw new InvalidInstrument('Instrument identity is locked after its first operation.', 'instrument_identity_locked');
        }

        return new self(
            $this->id, $this->workspace, $name, $isin, $ticker, $venueMic, $instrumentType,
            $assetClass, $region, $currencyCode, $quantityPrecision, $pricePrecision,
            $this->source, $at, $actorId, $this->status, $this->usedAt, $this->createdAt,
            $this->createdBy, $at, $this->version + 1,
        );
    }

    public function deactivate(\DateTimeImmutable $at): self
    {
        if (InstrumentStatus::INACTIVE === $this->status) {
            throw new InvalidInstrument('Instrument is already inactive.', 'instrument_already_inactive');
        }

        return $this->withStatus(InstrumentStatus::INACTIVE, $at);
    }

    public function reactivate(\DateTimeImmutable $at): self
    {
        if (InstrumentStatus::ACTIVE === $this->status) {
            throw new InvalidInstrument('Instrument is already active.', 'instrument_already_active');
        }

        return $this->withStatus(InstrumentStatus::ACTIVE, $at);
    }

    public function markUsed(\DateTimeImmutable $at): self
    {
        if (null !== $this->usedAt) {
            return $this;
        }

        return new self(
            $this->id, $this->workspace, $this->name, $this->isin, $this->ticker, $this->venueMic,
            $this->instrumentType, $this->assetClass, $this->region, $this->currencyCode,
            $this->quantityPrecision, $this->pricePrecision, $this->source, $this->reviewedAt,
            $this->reviewedBy, $this->status, $at, $this->createdAt, $this->createdBy, $at, $this->version + 1,
        );
    }

    public function mergeExternalMetadata(InstrumentMetadata $external, \DateTimeImmutable $at): InstrumentMetadataMerge
    {
        $conflicts = [];
        if (null === $this->reviewedAt) {
            $name = $external->name ?? $this->name;
            $ticker = $external->ticker ?? $this->ticker;
            $venueMic = $external->venueMic ?? $this->venueMic;
            $instrumentType = $external->instrumentType ?? $this->instrumentType;
            $assetClass = $external->assetClass ?? $this->assetClass;
            $region = $external->region ?? $this->region;
        } else {
            $name = $this->name;
            if (null !== $external->name && $external->name !== $name) {
                $conflicts[] = new InstrumentMetadataConflict('name', $name, $external->name);
            }
            $ticker = $this->ticker;
            if (null !== $external->ticker) {
                if (null === $ticker) {
                    $ticker = $external->ticker;
                } elseif (!$ticker->equals($external->ticker)) {
                    $conflicts[] = new InstrumentMetadataConflict('ticker', $ticker->toString(), $external->ticker->toString());
                }
            }
            $venueMic = $this->venueMic;
            if (null !== $external->venueMic) {
                if (null === $venueMic) {
                    $venueMic = $external->venueMic;
                } elseif (!$venueMic->equals($external->venueMic)) {
                    $conflicts[] = new InstrumentMetadataConflict('venueMic', $venueMic->toString(), $external->venueMic->toString());
                }
            }
            $instrumentType = $this->instrumentType;
            if (null !== $external->instrumentType && $external->instrumentType !== $instrumentType) {
                $conflicts[] = new InstrumentMetadataConflict('instrumentType', $instrumentType->value, $external->instrumentType->value);
            }
            $assetClass = $this->assetClass;
            if (null !== $external->assetClass) {
                if (null === $assetClass) {
                    $assetClass = $external->assetClass;
                } elseif ($external->assetClass !== $assetClass) {
                    $conflicts[] = new InstrumentMetadataConflict('assetClass', $assetClass->value, $external->assetClass->value);
                }
            }
            $region = $this->region;
            if (null !== $external->region) {
                if (null === $region) {
                    $region = $external->region;
                } elseif ($external->region !== $region) {
                    $conflicts[] = new InstrumentMetadataConflict('region', $region->value, $external->region->value);
                }
            }
        }

        $instrument = new self(
            $this->id, $this->workspace, $name, $this->isin, $ticker, $venueMic, $instrumentType, $assetClass, $region,
            $this->currencyCode, $this->quantityPrecision, $this->pricePrecision, $this->source,
            $this->reviewedAt, $this->reviewedBy, $this->status, $this->usedAt, $this->createdAt,
            $this->createdBy, $at, $this->version + 1,
        );

        return new InstrumentMetadataMerge($instrument, $conflicts);
    }

    private function withStatus(InstrumentStatus $status, \DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->workspace, $this->name, $this->isin, $this->ticker, $this->venueMic,
            $this->instrumentType, $this->assetClass, $this->region, $this->currencyCode,
            $this->quantityPrecision, $this->pricePrecision, $this->source, $this->reviewedAt,
            $this->reviewedBy, $status, $this->usedAt, $this->createdAt, $this->createdBy, $at, $this->version + 1,
        );
    }

    private static function normalizeDisplayName(string $name): string
    {
        if (1 === preg_match('/[\p{Cc}\p{Cf}]/u', $name)) {
            throw new InvalidInstrument('An instrument name must be plain text between 1 and 120 characters.', 'invalid_instrument_name');
        }

        $trimmed = self::trimUnicodeWhitespace($name);
        if ('' === $trimmed || mb_strlen($trimmed) > self::MAX_NAME_LENGTH) {
            throw new InvalidInstrument('An instrument name must be plain text between 1 and 120 characters.', 'invalid_instrument_name');
        }

        return $trimmed;
    }

    private static function trimUnicodeWhitespace(string $value): string
    {
        return preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value)
            ?? throw new InvalidInstrument('An instrument name must be valid Unicode text.', 'invalid_instrument_name');
    }

    private static function assertPrecision(int $precision): void
    {
        if ($precision < 0 || $precision > self::MAX_PRECISION) {
            throw new InvalidInstrument('Instrument precision must be between 0 and 12.', 'invalid_instrument_precision');
        }
    }

    private static function assertIdentifier(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidInstrument('An instrument identifier must be a canonical UUID.', 'invalid_instrument_identifier');
        }
    }

    private static function sameNullable(?Isin $left, ?Isin $right): bool
    {
        return null === $left ? null === $right : null !== $right && $left->equals($right);
    }
}
