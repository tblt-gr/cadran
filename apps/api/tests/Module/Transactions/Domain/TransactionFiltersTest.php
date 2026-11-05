<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\TransactionDirection;
use App\Module\Transactions\Domain\TransactionFilters;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use PHPUnit\Framework\TestCase;

final class TransactionFiltersTest extends TestCase
{
    private const array COLUMN_FILTERS = ['accountIds', 'states', 'natures', 'sources'];

    public function testWithoutColumnFiltersDropsTheFourColumnFiltersAndKeepsEveryOtherOne(): void
    {
        $filters = $this->everyFilterSet();

        $stripped = $filters->withoutColumnFilters();

        foreach (self::COLUMN_FILTERS as $property) {
            self::assertSame([], $stripped->{$property}, $property);
        }
        foreach ($this->properties() as $property) {
            if (!in_array($property, self::COLUMN_FILTERS, true)) {
                self::assertEquals($filters->{$property}, $stripped->{$property}, $property);
            }
        }
    }

    public function testTheSampleSetsEveryFilterSoAFilterAddedLaterCannotBeDroppedUnnoticed(): void
    {
        $constructor = (new \ReflectionClass(TransactionFilters::class))->getConstructor();
        self::assertNotNull($constructor);
        $filters = $this->everyFilterSet();

        foreach ($constructor->getParameters() as $parameter) {
            self::assertNotEquals(
                $parameter->getDefaultValue(),
                $filters->{$parameter->getName()},
                sprintf('Set "%s" in everyFilterSet(), then decide whether withoutColumnFilters() keeps it.', $parameter->getName()),
            );
        }
    }

    /** @return list<string> */
    private function properties(): array
    {
        $constructor = (new \ReflectionClass(TransactionFilters::class))->getConstructor();
        self::assertNotNull($constructor);

        return array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters());
    }

    private function everyFilterSet(): TransactionFilters
    {
        return new TransactionFilters(
            from: new \DateTimeImmutable('2026-01-01'),
            to: new \DateTimeImmutable('2026-01-31'),
            accountIds: ['00000000-0000-7000-8000-00000000a001'],
            states: [TransactionState::BOOKED],
            natures: [TransactionNature::EXPENSE],
            categoryIds: ['00000000-0000-7000-8000-00000000c001'],
            axes: [AnalyticAxis::ESSENTIAL],
            minAmount: DecimalValue::fromString('10'),
            maxAmount: DecimalValue::fromString('50'),
            assetCode: AssetCode::fromString('EUR'),
            sources: [TransactionSource::IMPORT],
            categorizationNone: true,
            q: 'cafe',
            direction: TransactionDirection::OUTFLOW,
            qAmount: DecimalValue::fromString('12.5'),
        );
    }
}
