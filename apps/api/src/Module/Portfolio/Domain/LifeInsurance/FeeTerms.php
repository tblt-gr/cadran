<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * One immutable, dated version of a contract's fee terms. Rates are percentages (`0.85` is 0.85 %), informative
 * only: nothing here produces a fee.
 */
final readonly class FeeTerms
{
    public const int MAX_SOURCE_NOTE_LENGTH = 200;
    public const int MAX_RATE_SCALE = 4;

    public ?string $sourceNote;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $contractId,
        public \DateTimeImmutable $validFrom,
        public ?DecimalValue $entryFeeRate,
        public ?DecimalValue $euroFundManagementFeeRate,
        public ?DecimalValue $unitLinkedManagementFeeRate,
        public ?DecimalValue $switchFeeRate,
        public ?DecimalValue $switchFeeFixed,
        ?string $sourceNote,
        public \DateTimeImmutable $recordedAt,
        public string $recordedBy,
    ) {
        foreach ([$entryFeeRate, $euroFundManagementFeeRate, $unitLinkedManagementFeeRate, $switchFeeRate] as $rate) {
            self::assertRate($rate);
        }
        if (null !== $switchFeeFixed && $switchFeeFixed->isNegative()) {
            throw new InvalidLifeInsurance('The fixed switch fee cannot be negative.', 'invalid_fee_rate');
        }
        $this->sourceNote = ContractText::optional($sourceNote, self::MAX_SOURCE_NOTE_LENGTH);
    }

    /**
     * The version in force on a day: the latest `valid_from` on or before it, or null before the first version.
     *
     * @param list<self> $versions
     */
    public static function applicableOn(array $versions, \DateTimeImmutable $day): ?self
    {
        $limit = $day->format('Y-m-d');
        $applicable = null;
        foreach ($versions as $version) {
            $from = $version->validFrom->format('Y-m-d');
            if ($from <= $limit && (null === $applicable || $from > $applicable->validFrom->format('Y-m-d'))) {
                $applicable = $version;
            }
        }

        return $applicable;
    }

    private static function assertRate(?DecimalValue $rate): void
    {
        if (null === $rate) {
            return;
        }
        if ($rate->isNegative() || $rate->scale() > self::MAX_RATE_SCALE || $rate->compareTo(DecimalValue::fromString('100')) > 0) {
            throw new InvalidLifeInsurance('A fee rate is a percentage between 0 and 100 with at most 4 decimals.', 'invalid_fee_rate');
        }
    }
}
