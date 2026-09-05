<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuation;
use App\Module\Accounts\Domain\ValuationQuality;
use App\Module\Foundation\Domain\DisplayedAmount;
use App\Module\Reference\Domain\Asset;

/**
 * The latest valid snapshot on a requested date, with source and display
 * kept distinct. A missing valuation stays null on both sides.
 */
final readonly class ValuationView
{
    public function __construct(
        public string $accountId,
        public string $requestedOn,
        public ?string $asOf,
        public ?string $amount,
        public ?string $assetCode,
        public ?string $displayAmount,
        public bool $belowDisplayStep,
        public ?string $source,
        public ?int $ageDays,
        public string $quality,
        public ?string $reconciliationStatus,
        public ?string $snapshotId,
        public ?int $version,
    ) {
    }

    public static function of(
        string $accountId,
        \DateTimeImmutable $requestedOn,
        AccountValuation $valuation,
        ?Asset $asset,
    ): self {
        $display = self::display($valuation, $asset);

        return new self(
            accountId: $accountId,
            requestedOn: $requestedOn->format('Y-m-d'),
            asOf: ValuationQuality::MISSING === $valuation->quality ? null : $valuation->asOf->format('Y-m-d'),
            amount: $valuation->amount?->value->toString(),
            assetCode: $valuation->amount?->asset->toString(),
            displayAmount: $display?->value?->toString(),
            belowDisplayStep: null !== $display && $display->belowStep,
            source: $valuation->source?->value,
            ageDays: $valuation->ageDays,
            quality: $valuation->quality->value,
            reconciliationStatus: $valuation->snapshot?->reconciliationStatus->value,
            snapshotId: $valuation->snapshot?->id,
            version: $valuation->snapshot?->version,
        );
    }

    public static function missing(string $accountId, \DateTimeImmutable $requestedOn): self
    {
        return new self(
            accountId: $accountId,
            requestedOn: $requestedOn->format('Y-m-d'),
            asOf: null,
            amount: null,
            assetCode: null,
            displayAmount: null,
            belowDisplayStep: false,
            source: null,
            ageDays: null,
            quality: ValuationQuality::MISSING->value,
            reconciliationStatus: null,
            snapshotId: null,
            version: null,
        );
    }

    private static function display(AccountValuation $valuation, ?Asset $asset): ?DisplayedAmount
    {
        if (null === $valuation->amount || null === $asset) {
            return null;
        }

        return $asset->displayed($valuation->amount->value);
    }
}
