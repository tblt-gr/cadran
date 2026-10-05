<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A rate the insurer announced for one euro fund and one year. It is a dated, sourced fact shown beside the
 * contract, never a performance and never an input of any computation.
 */
final readonly class EuroFundRate
{
    public const int MAX_SCALE = 4;

    public ?string $sourceNote;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $contractId,
        public string $instrumentId,
        public int $year,
        public DecimalValue $servedRate,
        public ?DecimalValue $bonusRate,
        public EuroFundRateBasis $basis,
        ?string $sourceNote,
        public \DateTimeImmutable $recordedAt,
        public string $recordedBy,
    ) {
        self::assertWithin($servedRate, '-100');
        if (null !== $bonusRate) {
            self::assertWithin($bonusRate, '0');
        }
        $this->sourceNote = ContractText::optional($sourceNote, FeeTerms::MAX_SOURCE_NOTE_LENGTH);
    }

    private static function assertWithin(DecimalValue $rate, string $lower): void
    {
        if ($rate->scale() > self::MAX_SCALE
            || $rate->compareTo(DecimalValue::fromString($lower)) < 0
            || $rate->compareTo(DecimalValue::fromString('100')) > 0) {
            throw new InvalidLifeInsurance('A euro-fund rate is a percentage within its bounds with at most 4 decimals.', 'invalid_euro_fund_rate');
        }
    }
}
