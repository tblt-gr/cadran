<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Reference\Domain\Asset;

/**
 * Reads regular movements out of a bounded slice of history and proposes them.
 *
 * The service is strictly read-only: it returns proposals and writes nothing,
 * because no recurrence, occurrence or transaction may exist before the user
 * confirms one. Every figure it produces is exact — medians, tolerance and gap
 * deviations are decimal arithmetic, never a float — and a group the rules
 * cannot recognise is dropped rather than approximated into the nearest rhythm.
 */
final class RecurrenceDetector
{
    /** The scan reads at most this much history, so it cannot become an exhaustion vector. */
    public const int WINDOW_MONTHS = 24;
    public const int MAX_OBSERVATIONS = 5000;
    public const int MIN_OCCURRENCES = 3;

    /** Every gap must sit this close to the median gap, otherwise the group is irregular. */
    private const string MAX_GAP_DEVIATION_DAYS = '4';
    private const string TOLERANCE_RATE = '0.02';
    /** Separates the fingerprint parts: a control character no label or identifier can carry. */
    private const string PART_SEPARATOR = "\x1f";

    /**
     * @param array<string, Asset> $assets the assets named by the window, by code
     */
    public function detect(RecurrenceObservationWindow $window, array $assets): RecurrenceCandidateScan
    {
        $candidates = [];
        foreach (self::groups($window->observations) as $group) {
            $candidate = $this->propose($group, $assets);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return new RecurrenceCandidateScan($candidates, $window->partial);
    }

    /**
     * @param non-empty-list<RecurrenceObservation> $group
     * @param array<string, Asset>                  $assets
     */
    private function propose(array $group, array $assets): ?RecurrenceCandidate
    {
        if (count($group) < self::MIN_OCCURRENCES) {
            return null;
        }

        $assetCode = $group[0]->amount->asset->toString();
        foreach ($group as $observation) {
            // Two denominations in one group have no comparable median: the
            // movements are not the same recurring charge.
            if ($observation->amount->asset->toString() !== $assetCode) {
                return null;
            }
        }
        $asset = $assets[$assetCode] ?? throw new \UnexpectedValueException(sprintf('Asset %s is missing from the detection reference.', $assetCode));

        $gaps = self::gaps($group);
        $medianGap = self::median($gaps);
        $interval = RecurrenceIntervalKind::classify($medianGap);
        if (null === $interval) {
            return null;
        }

        $deviation = self::widestDeviation($gaps, $medianGap);
        if ($deviation->compareTo(DecimalValue::fromString(self::MAX_GAP_DEVIATION_DAYS)) > 0) {
            return null;
        }

        $medianAmount = self::median(array_map(static fn (RecurrenceObservation $o): DecimalValue => $o->amount->value, $group));
        $tolerance = self::tolerance($medianAmount, $asset);
        if (!self::allWithin($group, $medianAmount, $tolerance)) {
            return null;
        }

        $last = $group[count($group) - 1];
        $confidence = RecurrenceConfidence::classify(count($group), $deviation);

        return new RecurrenceCandidate(
            fingerprint: self::fingerprint($group[0]->accountId, $group[0]->groupingKey, $interval),
            accountId: $group[0]->accountId,
            groupingKey: $group[0]->groupingKey,
            counterparty: $last->displayName,
            intervalKind: $interval,
            medianGapDays: $medianGap,
            medianAmount: new AssetAmount($medianAmount, $last->amount->asset),
            tolerance: new AssetAmount($tolerance, $last->amount->asset),
            occurrenceCount: count($group),
            firstSeenOn: $group[0]->bookedOn,
            lastSeenOn: $last->bookedOn,
            confidence: $confidence,
            confidenceReason: null === $confidence ? RecurrenceConfidenceReason::UNCLASSIFIED_GAP_SPREAD : null,
        );
    }

    /**
     * Groups by account and grouping key, each group ordered by booked date.
     * Sorting the keys makes the proposal order stable across scans.
     *
     * @param list<RecurrenceObservation> $observations
     *
     * @return list<non-empty-list<RecurrenceObservation>>
     */
    private static function groups(array $observations): array
    {
        /** @var array<string, non-empty-list<RecurrenceObservation>> $groups */
        $groups = [];
        foreach ($observations as $observation) {
            $groups[$observation->accountId.self::PART_SEPARATOR.$observation->groupingKey][] = $observation;
        }
        ksort($groups);

        return array_values(array_map(static function (array $group): array {
            usort($group, static fn (RecurrenceObservation $left, RecurrenceObservation $right): int => [$left->bookedOn->format('Y-m-d'), $left->transactionId] <=> [$right->bookedOn->format('Y-m-d'), $right->transactionId]);

            return $group;
        }, $groups));
    }

    /**
     * @param non-empty-list<RecurrenceObservation> $group
     *
     * @return list<DecimalValue> the gaps between consecutive movements, in days
     */
    private static function gaps(array $group): array
    {
        $gaps = [];
        for ($index = 1; $index < count($group); ++$index) {
            $difference = $group[$index - 1]->bookedOn->diff($group[$index]->bookedOn);
            $gaps[] = DecimalValue::fromString((string) ($difference->days ?: 0));
        }

        return $gaps;
    }

    /**
     * The exact median: the central value, or the arithmetic mean of the two
     * central values. Dividing by two adds at most one decimal place, so the
     * result is written at that scale and stays exact — a day median may
     * therefore legitimately end in `.5`.
     *
     * @param list<DecimalValue> $values
     */
    private static function median(array $values): DecimalValue
    {
        if ([] === $values) {
            throw new InvalidRecurrence('A median needs at least one observation.');
        }
        usort($values, static fn (DecimalValue $left, DecimalValue $right): int => $left->compareTo($right));
        $count = count($values);
        $middle = intdiv($count, 2);
        if (1 === $count % 2) {
            return $values[$middle];
        }

        $lower = $values[$middle - 1];
        $upper = $values[$middle];
        if (0 === $lower->compareTo($upper)) {
            return $lower;
        }

        return ExactDecimal::divide(
            ExactDecimal::add($lower, $upper),
            DecimalValue::fromString('2'),
            max($lower->scale(), $upper->scale()) + 1,
        );
    }

    /**
     * @param list<DecimalValue> $gaps
     */
    private static function widestDeviation(array $gaps, DecimalValue $median): DecimalValue
    {
        $widest = DecimalValue::zero();
        foreach ($gaps as $gap) {
            $deviation = ExactDecimal::absolute(ExactDecimal::subtract($gap, $median));
            if ($deviation->compareTo($widest) > 0) {
                $widest = $deviation;
            }
        }

        return $widest;
    }

    /**
     * `max(|A| × 2 %, one display unit)`, at the asset's display precision. The
     * display unit is the floor: on a small amount two percent would round to
     * nothing and no real movement would ever match.
     */
    private static function tolerance(DecimalValue $medianAmount, Asset $asset): DecimalValue
    {
        $rate = ExactDecimal::round(
            ExactDecimal::multiply(ExactDecimal::absolute($medianAmount), DecimalValue::fromString(self::TOLERANCE_RATE)),
            $asset->precision->display,
            $asset->roundingMode,
        );
        $step = $asset->displayStep()->value;

        return $rate->compareTo($step) >= 0 ? $rate : $step;
    }

    /**
     * @param non-empty-list<RecurrenceObservation> $group
     */
    private static function allWithin(array $group, DecimalValue $medianAmount, DecimalValue $tolerance): bool
    {
        $floor = ExactDecimal::subtract($medianAmount, $tolerance);
        $ceiling = ExactDecimal::add($medianAmount, $tolerance);

        foreach ($group as $observation) {
            $amount = $observation->amount->value;
            if ($amount->compareTo($floor) < 0 || $amount->compareTo($ceiling) > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable across scans, so dismissing a candidate keeps it dismissed. The
     * parts are separated rather than concatenated: two different groupings
     * must never hash to the same fingerprint.
     */
    private static function fingerprint(string $accountId, string $groupingKey, RecurrenceIntervalKind $interval): string
    {
        return hash('sha256', implode(self::PART_SEPARATOR, [$accountId, $groupingKey, $interval->value]));
    }
}
