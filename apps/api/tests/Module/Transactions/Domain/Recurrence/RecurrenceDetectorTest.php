<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reference\Domain\Asset;
use App\Module\Reference\Domain\AssetKind;
use App\Module\Reference\Domain\AssetPrecision;
use App\Module\Transactions\Domain\Recurrence\RecurrenceCandidate;
use App\Module\Transactions\Domain\Recurrence\RecurrenceCandidateScan;
use App\Module\Transactions\Domain\Recurrence\RecurrenceConfidence;
use App\Module\Transactions\Domain\Recurrence\RecurrenceConfidenceReason;
use App\Module\Transactions\Domain\Recurrence\RecurrenceDetector;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservation;
use App\Module\Transactions\Domain\Recurrence\RecurrenceObservationWindow;
use PHPUnit\Framework\TestCase;

final class RecurrenceDetectorTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';

    public function testTheWorkedExampleIsDisqualifiedByItsOutlyingAmount(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-04', '-15.99'],
            ['2026-01-03', '-14.99'],
            ['2026-02-01', '-14.99'],
        ]);

        self::assertSame([], $scan->candidates);
    }

    public function testTheWorkedExampleWithoutItsOutlierIsProposedWithAnExactMedianAndTolerance(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-04', '-14.99'],
            ['2026-01-03', '-14.99'],
            ['2026-02-01', '-14.99'],
        ]);

        $candidate = $this->single($scan->candidates);
        self::assertSame(RecurrenceIntervalKind::MONTHLY, $candidate->intervalKind);
        self::assertSame('-14.99', $candidate->medianAmount->value->toString());
        // max(14.99 × 0.02, 0.01) = 0.2998 at the euro display precision.
        self::assertSame('0.30', $candidate->tolerance->value->toString());
        self::assertSame('EUR', $candidate->tolerance->asset->toString());
        self::assertSame(5, $candidate->occurrenceCount);
        self::assertSame('2025-10-04', $candidate->firstSeenOn->format('Y-m-d'));
        self::assertSame('2026-02-01', $candidate->lastSeenOn->format('Y-m-d'));
        self::assertSame(RecurrenceConfidence::MEDIUM, $candidate->confidence);
        self::assertNull($candidate->confidenceReason);
    }

    public function testAnAmountExactlyOnTheToleranceBoundStaysInside(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-04', '-15.29'],
            ['2026-01-03', '-14.99'],
            ['2026-02-01', '-14.99'],
        ]);

        self::assertSame('-14.99', $this->single($scan->candidates)->medianAmount->value->toString());
    }

    public function testTheFingerprintIsTheSha256OfTheAccountTheGroupingKeyAndTheInterval(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-04', '-14.99'],
        ]);

        self::assertSame(
            hash('sha256', implode("\x1f", [self::ACCOUNT, 'netflix', 'MONTHLY'])),
            $this->single($scan->candidates)->fingerprint,
        );
    }

    public function testAGroupOfTwoOccurrencesIsNeverProposed(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
        ]);

        self::assertSame([], $scan->candidates);
    }

    public function testSameDayMovementsNameNoRhythm(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-10-04', '-14.99'],
            ['2025-10-04', '-14.99'],
        ]);

        self::assertSame([], $scan->candidates);
    }

    public function testAGapBeyondFourDaysFromTheMedianDisqualifiesTheGroup(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-03', '-14.99'],
            ['2026-01-08', '-14.99'],
        ]);

        self::assertSame([], $scan->candidates);
    }

    public function testSixTightOccurrencesReadHigh(): void
    {
        $scan = $this->detect([
            ['2025-09-04', '-14.99'],
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-03', '-14.99'],
            ['2026-01-02', '-14.99'],
            ['2026-02-01', '-14.99'],
        ]);

        self::assertSame(RecurrenceConfidence::HIGH, $this->single($scan->candidates)->confidence);
    }

    public function testThreeOccurrencesReadLowOnAHalfDayMedianGap(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-04', '-14.99'],
        ]);

        $candidate = $this->single($scan->candidates);
        self::assertSame(RecurrenceConfidence::LOW, $candidate->confidence);
        self::assertSame('30.5', $candidate->medianGapDays->toString());
    }

    public function testAnEvenNumberOfAmountsTakesTheirExactArithmeticMean(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-10.00'],
            ['2025-11-03', '-10.00'],
            ['2025-12-03', '-10.01'],
            ['2026-01-02', '-10.01'],
        ]);

        $candidate = $this->single($scan->candidates);
        self::assertSame('-10.005', $candidate->medianAmount->value->toString());
        self::assertSame('0.20', $candidate->tolerance->value->toString());
        self::assertSame(RecurrenceConfidence::MEDIUM, $candidate->confidence);
    }

    public function testASpreadTheTableCannotClassifyCarriesAReasonAndNoFigure(): void
    {
        $scan = $this->detect([
            ['2025-10-04', '-14.99'],
            ['2025-11-03', '-14.99'],
            ['2025-12-07', '-14.99'],
            ['2026-01-02', '-14.99'],
        ]);

        $candidate = $this->single($scan->candidates);
        self::assertNull($candidate->confidence);
        self::assertSame(RecurrenceConfidenceReason::UNCLASSIFIED_GAP_SPREAD, $candidate->confidenceReason);
    }

    public function testTheSameCounterpartyOnTwoAccountsNeverMergesIntoOneCandidate(): void
    {
        $observations = array_merge(
            $this->observations([['2025-10-04', '-14.99'], ['2025-11-03', '-14.99'], ['2025-12-03', '-14.99']], self::ACCOUNT, 'netflix', 0),
            $this->observations([['2025-10-06', '-14.99'], ['2025-11-05', '-14.99'], ['2025-12-05', '-14.99']], self::OTHER_ACCOUNT, 'netflix', 10),
        );

        $scan = (new RecurrenceDetector())->detect($this->window($observations), $this->assets());

        self::assertCount(2, $scan->candidates);
        self::assertSame([self::ACCOUNT, self::OTHER_ACCOUNT], array_map(
            static fn (RecurrenceCandidate $candidate): string => $candidate->accountId,
            $scan->candidates,
        ));
    }

    public function testTwoGroupingKeysOnOneAccountStayApart(): void
    {
        $observations = array_merge(
            $this->observations([['2025-10-04', '-14.99'], ['2025-11-03', '-14.99'], ['2025-12-03', '-14.99']], self::ACCOUNT, 'netflix', 0),
            $this->observations([['2025-10-06', '-9.99'], ['2025-11-05', '-9.99'], ['2025-12-05', '-9.99']], self::ACCOUNT, 'spotify', 10),
        );

        $scan = (new RecurrenceDetector())->detect($this->window($observations), $this->assets());

        self::assertCount(2, $scan->candidates);
    }

    public function testAGroupDenominatedInTwoAssetsHasNoComparableMedian(): void
    {
        $observations = [
            $this->observation(0, self::ACCOUNT, 'netflix', '2025-10-04', '-14.99', 'EUR'),
            $this->observation(1, self::ACCOUNT, 'netflix', '2025-11-03', '-14.99', 'USD'),
            $this->observation(2, self::ACCOUNT, 'netflix', '2025-12-03', '-14.99', 'EUR'),
        ];

        $scan = (new RecurrenceDetector())->detect($this->window($observations), $this->assets());

        self::assertSame([], $scan->candidates);
    }

    public function testAnObservationFromAnotherWorkspaceCannotEnterTheWindow(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new RecurrenceObservationWindow(
            WorkspaceScope::fromString(self::WORKSPACE),
            [
                $this->observation(0, self::ACCOUNT, 'netflix', '2025-10-04', '-14.99'),
                $this->observation(1, self::ACCOUNT, 'netflix', '2025-11-03', '-14.99', 'EUR', self::OTHER_WORKSPACE),
            ],
            false,
        );
    }

    public function testATruncatedWindowIsReportedAsPartial(): void
    {
        $scan = (new RecurrenceDetector())->detect(
            $this->window($this->observations([['2025-10-04', '-14.99'], ['2025-11-03', '-14.99'], ['2025-12-03', '-14.99']]), true),
            $this->assets(),
        );

        self::assertTrue($scan->partial);
        self::assertCount(1, $scan->candidates);
    }

    public function testACompleteWindowIsNotPartial(): void
    {
        self::assertFalse($this->detect([['2025-10-04', '-14.99'], ['2025-11-03', '-14.99'], ['2025-12-03', '-14.99']])->partial);
    }

    /**
     * @param list<array{string, string}> $movements
     */
    private function detect(array $movements): RecurrenceCandidateScan
    {
        return (new RecurrenceDetector())->detect($this->window($this->observations($movements)), $this->assets());
    }

    /**
     * @param list<array{string, string}> $movements
     *
     * @return list<RecurrenceObservation>
     */
    private function observations(array $movements, string $accountId = self::ACCOUNT, string $groupingKey = 'netflix', int $offset = 0): array
    {
        return array_map(
            fn (int $index): RecurrenceObservation => $this->observation(
                $offset + $index,
                $accountId,
                $groupingKey,
                $movements[$index][0],
                $movements[$index][1],
            ),
            array_keys($movements),
        );
    }

    private function observation(
        int $index,
        string $accountId,
        string $groupingKey,
        string $bookedOn,
        string $amount,
        string $assetCode = 'EUR',
        string $workspace = self::WORKSPACE,
    ): RecurrenceObservation {
        return new RecurrenceObservation(
            sprintf('00000000-0000-7000-8000-%012d', $index + 1),
            WorkspaceScope::fromString($workspace),
            $accountId,
            $groupingKey,
            'Netflix',
            new \DateTimeImmutable($bookedOn, new \DateTimeZone('UTC')),
            new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString($assetCode)),
        );
    }

    /**
     * @param list<RecurrenceObservation> $observations
     */
    private function window(array $observations, bool $partial = false): RecurrenceObservationWindow
    {
        return new RecurrenceObservationWindow(WorkspaceScope::fromString(self::WORKSPACE), $observations, $partial);
    }

    /** @return array<string, Asset> */
    private function assets(): array
    {
        return [
            'EUR' => new Asset(AssetCode::fromString('EUR'), AssetKind::FIAT, 'Euro', new AssetPrecision(24, 2), RoundingMode::HALF_UP),
            'USD' => new Asset(AssetCode::fromString('USD'), AssetKind::FIAT, 'Dollar', new AssetPrecision(24, 2), RoundingMode::HALF_UP),
        ];
    }

    /**
     * @param list<RecurrenceCandidate> $candidates
     */
    private function single(array $candidates): RecurrenceCandidate
    {
        self::assertCount(1, $candidates);

        return $candidates[0];
    }
}
