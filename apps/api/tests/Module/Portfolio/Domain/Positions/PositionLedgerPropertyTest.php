<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Positions;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\OperationDetails;
use App\Module\Portfolio\Domain\OperationState;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PositionLedgerPropertyTest extends TestCase
{
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const int RUNS = 200;
    private const array INSTRUMENTS = [
        '00000000-0000-7000-8000-000000000b01',
        '00000000-0000-7000-8000-000000000b02',
        '00000000-0000-7000-8000-000000000b03',
    ];

    #[Test]
    public function itKeepsLotQuantitiesEqualToThePositionAndFifoCostConservedOverRandomSequences(): void
    {
        for ($run = 0; $run < self::RUNS; ++$run) {
            [$operations, $acquired] = $this->sequence($run);
            $result = (new PositionLedger())->replay($operations, self::precisions());

            foreach (self::INSTRUMENTS as $instrument) {
                $position = $result->positions[$instrument] ?? null;
                if (null === $position) {
                    continue;
                }
                $lotQuantity = CalculatedDecimal::zero();
                $lotCost = CalculatedDecimal::zero();
                foreach ($position->openLots as $lot) {
                    $lotQuantity = $lotQuantity->add($lot->openQuantity);
                    self::assertNotNull($lot->costBasis, "run $run");
                    $lotCost = $lotCost->add($lot->costBasis);
                }
                self::assertSame(0, $position->quantity->compareTo($lotQuantity), "run $run quantity $instrument");

                $released = CalculatedDecimal::zero();
                foreach ($result->movements[$instrument] ?? [] as $movement) {
                    if (OperationType::SELL === $movement->type) {
                        self::assertNotNull($movement->costDelta, "run $run");
                        $released = $released->add($movement->costDelta->negate());
                    }
                }
                self::assertSame(0, ($acquired[$instrument] ?? CalculatedDecimal::zero())->compareTo($released->add($lotCost)), "run $run fifo cost $instrument");
                self::assertSame(0, $position->fifoCostBasis?->compareTo($lotCost), "run $run fifo basis $instrument");
            }
        }
    }

    #[Test]
    public function itKeepsAverageCostReleasedPlusRemainingEqualToAcquisitionsOverRandomSequences(): void
    {
        for ($run = 0; $run < self::RUNS; ++$run) {
            [$operations, $acquired] = $this->sequence($run);
            $ledger = new PositionLedger();
            $previous = [];
            $released = [];
            foreach ($operations as $index => $operation) {
                $instrument = self::required($operation->details->instrumentId);
                $result = $ledger->replay(array_slice($operations, 0, $index + 1), self::precisions());
                $pool = $result->positions[$instrument]->averageCostBasis;
                self::assertNotNull($pool, "run $run");
                $before = $previous[$instrument] ?? CalculatedDecimal::zero();
                if (OperationType::SELL === $operation->details->type) {
                    $relief = $before->subtract($pool);
                    self::assertGreaterThanOrEqual(0, $relief->compareTo(CalculatedDecimal::zero()), "run $run relief sign");
                    self::assertLessThanOrEqual(0, $relief->compareTo($before), "run $run relief bound");
                    $released[$instrument] = ($released[$instrument] ?? CalculatedDecimal::zero())->add($relief);
                }
                if ($result->positions[$instrument]->quantity->isZero()) {
                    self::assertTrue($pool->isZero(), "run $run empty pool");
                }
                $previous[$instrument] = $pool;
            }
            foreach ($previous as $instrument => $pool) {
                $total = ($released[$instrument] ?? CalculatedDecimal::zero())->add($pool);
                self::assertSame(0, $acquired[$instrument]->compareTo($total), "run $run average cost $instrument");
            }
        }
    }

    /**
     * Valid by construction: a sale never exceeds the quantity currently held. Decimals come from a seeded
     * generator so a failing run is reproducible from its index alone.
     *
     * @return array{list<PortfolioOperation>, array<string, CalculatedDecimal>} operations and acquisition cost per instrument
     */
    private function sequence(int $run): array
    {
        mt_srand(20260604 + $run);
        $held = [];
        $acquired = [];
        $operations = [];
        $count = mt_rand(2, 12);
        for ($step = 1; $step <= $count; ++$step) {
            $instrument = self::INSTRUMENTS[mt_rand(0, 2)];
            $date = (new \DateTimeImmutable('2026-01-01'))->modify('+'.$step.' days')->format('Y-m-d');
            $suffix = str_pad((string) $step, 2, '0', STR_PAD_LEFT);
            $onHand = $held[$instrument] ?? 0;
            if ($onHand > 0 && 1 === mt_rand(0, 2)) {
                $quantity = mt_rand(1, $onHand);
                $held[$instrument] = $onHand - $quantity;
                $operations[] = $this->operation($suffix, OperationType::SELL, $date, 1, $instrument, (string) $quantity, gross: self::money());
                continue;
            }
            $quantity = mt_rand(1, 20);
            $gross = self::money();
            $fees = self::money(1000);
            $held[$instrument] = $onHand + $quantity;
            $cost = CalculatedDecimal::fromDecimal(DecimalValue::fromString($gross))->add(CalculatedDecimal::fromDecimal(DecimalValue::fromString($fees)));
            $acquired[$instrument] = ($acquired[$instrument] ?? CalculatedDecimal::zero())->add($cost);
            $operations[] = $this->operation($suffix, OperationType::BUY, $date, 1, $instrument, (string) $quantity, gross: $gross, fees: $fees);
        }

        return [$operations, $acquired];
    }

    private static function money(int $maximumCents = 500000): string
    {
        $cents = mt_rand(1, $maximumCents);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<string, int> */
    private static function precisions(): array
    {
        return array_fill_keys(self::INSTRUMENTS, 6);
    }

    private static function required(?string $value): string
    {
        self::assertNotNull($value);

        return $value;
    }

    /** @param array{0: string, 1: string}|null $ratio */
    private function operation(
        string $suffix,
        OperationType $type,
        string $date,
        int $sequence,
        string $instrument,
        ?string $quantity = null,
        ?string $gross = null,
        ?string $fees = null,
        ?string $taxes = null,
        ?string $counter = null,
        ?string $counterQuantity = null,
        ?array $ratio = null,
        ?string $allocation = null,
    ): PortfolioOperation {
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, $instrument, $counter,
            null === $quantity ? null : DecimalValue::fromString($quantity),
            in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::fromString('1') : null,
            null === $gross ? null : DecimalValue::fromString($gross),
            null === $fees ? (in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::zero() : null) : DecimalValue::fromString($fees),
            null === $taxes ? (in_array($type, [OperationType::BUY, OperationType::SELL], true) ? DecimalValue::zero() : null) : DecimalValue::fromString($taxes),
            null, null === $counterQuantity ? null : DecimalValue::fromString($counterQuantity),
            null === $ratio ? null : DecimalValue::fromString($ratio[0]),
            null === $ratio ? null : DecimalValue::fromString($ratio[1]),
            null === $allocation ? null : DecimalValue::fromString($allocation),
            null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000'.$suffix,
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), self::ACCOUNT, AssetCode::fromString('EUR'),
            $details, $sequence, DecimalValue::zero(), null, OperationState::ACTIVE, null, null, null, null,
            null, new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
