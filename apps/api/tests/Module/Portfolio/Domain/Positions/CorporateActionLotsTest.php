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
use App\Module\Portfolio\Domain\OperationVoidKind;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\CorporateActionLots;
use App\Module\Portfolio\Domain\Positions\LedgerState;
use App\Module\Portfolio\Domain\Positions\LotMovement;
use App\Module\Portfolio\Domain\Positions\OpenLot;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorporateActionLotsTest extends TestCase
{
    private const string SOURCE = '00000000-0000-7000-8000-000000000b01';
    private const string TARGET = '00000000-0000-7000-8000-000000000b02';

    #[Test]
    public function splitScalesEveryLotAndKeepsItsCost(): void
    {
        $states = [self::SOURCE => $this->state([['1', '10', '100'], ['2', '5', '60']])];
        $movements = [];

        (new CorporateActionLots())->split($states, $movements, $this->operation(OperationType::SPLIT, ratio: ['2', '1']), self::SOURCE, 0);

        $state = $states[self::SOURCE];
        self::assertSame('30', $state->poolQuantity->toString());
        self::assertSame(['20', '10'], array_map(static fn (OpenLot $lot): string => $lot->openQuantity->toString(), $state->lots));
        self::assertSame('100', $state->lots[0]->costBasis?->toString());
        self::assertSame(['10', '5'], array_map(static fn (LotMovement $m): string => $m->quantityDelta->toString(), $movements[self::SOURCE]));
        self::assertSame('0', $movements[self::SOURCE][0]->costDelta?->toString());
    }

    #[Test]
    public function splitWithoutPositionFails(): void
    {
        $states = [self::SOURCE => new LedgerState()];
        $movements = [];

        $this->expectFailure('corporate_action_without_position');
        (new CorporateActionLots())->split($states, $movements, $this->operation(OperationType::SPLIT, ratio: ['2', '1']), self::SOURCE, 0);
    }

    #[Test]
    public function splitRejectsAFractionBeyondTheQuantityPrecision(): void
    {
        $states = [self::SOURCE => $this->state([['1', '3', '30']])];
        $movements = [];

        $this->expectFailure('split_fraction_unsupported');
        (new CorporateActionLots())->split($states, $movements, $this->operation(OperationType::SPLIT, ratio: ['1', '2']), self::SOURCE, 0);
    }

    #[Test]
    public function mergeMovesAllLotsToTheTargetAndCarriesTheCostOfAZeroQuantityLot(): void
    {
        $states = [self::SOURCE => $this->state([['1', '3', '30'], ['2', '3', '60']]), self::TARGET => new LedgerState()];
        $movements = [];

        (new CorporateActionLots())->merge($states, $movements, $this->operation(OperationType::MERGER, counter: '1'), self::SOURCE, self::TARGET, $this->decimal('1'), 0);

        self::assertSame('0', $states[self::SOURCE]->poolQuantity->toString());
        self::assertSame([], $states[self::SOURCE]->lots);
        $target = $states[self::TARGET];
        self::assertSame('1', $target->poolQuantity->toString());
        self::assertSame('90', $target->poolCost?->toString());
        // The first lot gets floor(1 * 3/6) = 0, so its cost is carried onto the last lot.
        self::assertCount(1, $target->lots);
        self::assertSame('1', $target->lots[0]->openQuantity->toString());
        self::assertSame('90', $target->lots[0]->costBasis?->toString());
        self::assertSame('-30', $movements[self::SOURCE][0]->costDelta?->toString());
    }

    #[Test]
    public function mergeKeepsAnUnknownCostNull(): void
    {
        $states = [self::SOURCE => $this->state([['1', '3', null], ['2', '3', '60']]), self::TARGET => new LedgerState()];
        $movements = [];

        (new CorporateActionLots())->merge($states, $movements, $this->operation(OperationType::MERGER, counter: '1'), self::SOURCE, self::TARGET, $this->decimal('1'), 0);

        self::assertNull($states[self::TARGET]->lots[0]->costBasis);
        self::assertNull($states[self::TARGET]->poolCost);
    }

    #[Test]
    public function mergeWithoutPositionFails(): void
    {
        $states = [self::SOURCE => new LedgerState(), self::TARGET => new LedgerState()];
        $movements = [];

        $this->expectFailure('corporate_action_without_position');
        (new CorporateActionLots())->merge($states, $movements, $this->operation(OperationType::MERGER, counter: '1'), self::SOURCE, self::TARGET, $this->decimal('1'), 0);
    }

    #[Test]
    public function spinOffAllocatesCostToTheTargetAndKeepsTheParentQuantity(): void
    {
        $states = [self::SOURCE => $this->state([['1', '10', '100']]), self::TARGET => new LedgerState()];
        $movements = [];

        (new CorporateActionLots())->spinOff($states, $movements, $this->operation(OperationType::SPIN_OFF, counter: '5'), self::SOURCE, self::TARGET, $this->decimal('5'), $this->decimal('0.25'), 0);

        $parent = $states[self::SOURCE];
        self::assertSame('10', $parent->poolQuantity->toString());
        self::assertSameAmount('75', $parent->poolCost);
        self::assertSameAmount('75', $parent->lots[0]->costBasis);
        self::assertSameAmount('-25', $movements[self::SOURCE][0]->costDelta);
        $child = $states[self::TARGET];
        self::assertSame('5', $child->poolQuantity->toString());
        self::assertSameAmount('25', $child->poolCost);
        self::assertSameAmount('25', $child->lots[0]->costBasis);
    }

    #[Test]
    public function spinOffKeepsUnknownCostsNull(): void
    {
        $states = [self::SOURCE => $this->state([['1', '10', null]]), self::TARGET => new LedgerState()];
        $movements = [];

        (new CorporateActionLots())->spinOff($states, $movements, $this->operation(OperationType::SPIN_OFF, counter: '5'), self::SOURCE, self::TARGET, $this->decimal('5'), $this->decimal('0.25'), 0);

        self::assertNull($states[self::SOURCE]->lots[0]->costBasis);
        self::assertNull($movements[self::SOURCE][0]->costDelta);
        self::assertNull($states[self::TARGET]->poolCost);
    }

    #[Test]
    public function directionsFlagIncreasesAndDecreasesPerInstrumentAndDay(): void
    {
        $directions = (new CorporateActionLots())->directions([
            $this->operation(OperationType::BUY, date: '2026-01-02'),
            $this->operation(OperationType::SELL, date: '2026-01-02'),
            $this->operation(OperationType::MERGER, date: '2026-01-03', counter: '1'),
            $this->operation(OperationType::SPLIT, date: '2026-01-04', ratio: ['1', '2']),
        ]);

        self::assertSame(['increase' => true, 'decrease' => true], $directions[self::SOURCE]['2026-01-02']);
        self::assertSame(['increase' => false, 'decrease' => true], $directions[self::SOURCE]['2026-01-03']);
        self::assertSame(['increase' => true, 'decrease' => false], $directions[self::TARGET]['2026-01-03']);
        self::assertSame(['increase' => false, 'decrease' => true], $directions[self::SOURCE]['2026-01-04']);
    }

    #[Test]
    public function directionsIgnoreInactiveOperationsAndAreEmptyWithoutOperations(): void
    {
        self::assertSame([], (new CorporateActionLots())->directions([]));
        self::assertSame([], (new CorporateActionLots())->directions([$this->operation(OperationType::BUY, state: OperationState::VOIDED)]));
    }

    private function expectFailure(string $code): void
    {
        $this->expectException(PositionReplayFailed::class);
        $this->expectExceptionMessage($code);
    }

    private static function assertSameAmount(string $expected, ?CalculatedDecimal $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame(0, $actual->compareTo(CalculatedDecimal::fromDecimal(DecimalValue::fromString($expected))), $expected.' expected, got '.$actual->toString());
    }

    private function decimal(string $value): CalculatedDecimal
    {
        return CalculatedDecimal::fromDecimal(DecimalValue::fromString($value));
    }

    /** @param list<array{string, string, ?string}> $lots id suffix, open quantity, cost */
    private function state(array $lots): LedgerState
    {
        $state = new LedgerState();
        $quantity = CalculatedDecimal::zero();
        $cost = CalculatedDecimal::zero();
        foreach ($lots as $index => [$id, $open, $lotCost]) {
            $openQuantity = $this->decimal($open);
            $lotCostDecimal = null === $lotCost ? null : $this->decimal($lotCost);
            $state->lots[] = new OpenLot('lot-'.$id, 'op-'.$id, OperationType::BUY, new \DateTimeImmutable('2026-01-0'.($index + 1)), $index + 1, $openQuantity, $openQuantity, $lotCostDecimal);
            $quantity = $quantity->add($openQuantity);
            $cost = null === $cost || null === $lotCostDecimal ? null : $cost->add($lotCostDecimal);
        }
        $state->poolQuantity = $quantity;
        $state->poolCost = $cost;

        return $state;
    }

    /** @param array{string, string}|null $ratio */
    private function operation(OperationType $type, string $date = '2026-02-01', ?string $counter = null, ?array $ratio = null, OperationState $state = OperationState::ACTIVE): PortfolioOperation
    {
        $trade = in_array($type, [OperationType::BUY, OperationType::SELL], true);
        $details = new OperationDetails(
            $type, new \DateTimeImmutable($date), null, self::SOURCE, null === $counter ? null : self::TARGET,
            $trade ? DecimalValue::fromString('1') : null,
            $trade ? DecimalValue::fromString('1') : null,
            $trade ? DecimalValue::fromString('1') : null,
            $trade ? DecimalValue::zero() : null,
            $trade ? DecimalValue::zero() : null,
            null, null === $counter ? null : DecimalValue::fromString($counter),
            null === $ratio ? null : DecimalValue::fromString($ratio[0]),
            null === $ratio ? null : DecimalValue::fromString($ratio[1]),
            OperationType::SPIN_OFF === $type ? DecimalValue::fromString('0.25') : null,
            null, null, null,
        );

        return new PortfolioOperation(
            '00000000-0000-7000-8000-0000000000e1',
            WorkspaceScope::fromString('00000000-0000-7000-8000-000000000001'), '00000000-0000-7000-8000-0000000000d1', AssetCode::fromString('EUR'),
            $details, 1, DecimalValue::zero(), null, $state, OperationState::VOIDED === $state ? new \DateTimeImmutable('2026-01-05') : null, OperationState::VOIDED === $state ? '00000000-0000-7000-8000-000000000002' : null, null, OperationState::VOIDED === $state ? OperationVoidKind::VOIDED : null, null,
            new \DateTimeImmutable('2026-01-01'), '00000000-0000-7000-8000-000000000002', 1,
        );
    }
}
