<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\Overview;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\Overview\OverviewFigures;
use PHPUnit\Framework\TestCase;

final class OverviewFiguresTest extends TestCase
{
    public function testUnrealizedGainUsesFrozenCostWithoutRounding(): void
    {
        self::assertSame('121.421', OverviewFigures::gain('2471.421', ['2350.00']));
        self::assertSame('99999999999999999999999999.999999999999999999999999', OverviewFigures::gain('99999999999999999999999999.999999999999999999999999', ['0']));
        self::assertNull(OverviewFigures::gain('2471.421', [null]));
    }

    public function testAllocationKeepsTheTableAndLimitsTheSlicesToEight(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; ++$i) {
            $items[] = ['key' => 'I'.$i, 'label' => 'Instrument '.$i, 'value' => (string) $i];
        }
        $result = OverviewFigures::allocation($items, DecimalValue::fromString('55'));
        self::assertCount(10, $result['items']);
        self::assertCount(8, $result['slices']);
        self::assertSame(['key' => 'OTHERS', 'label' => 'Autres', 'value' => '6', 'share' => '0.109090909090909090909091'], $result['slices'][7]);
        self::assertSame('0.018181818181818181818182', $result['items'][0]['share']);
    }

    public function testLargeSignedValuesKeepResponseSharesBeyondStorageWidth(): void
    {
        $items = [
            ['key' => 'SECURITIES', 'label' => 'Titres', 'value' => '10000000000000000000000000'],
            ['key' => 'CASH', 'label' => 'Espèces', 'value' => '-9999999999999999999999999.99'],
        ];
        $result = OverviewFigures::allocation($items, DecimalValue::fromString('0.01'));
        self::assertSame('1000000000000000000000000000.000000000000000000000000', $result['items'][0]['share']);
        self::assertSame('-999999999999999999999999999.000000000000000000000000', $result['items'][1]['share']);
        self::assertSame($items[0]['value'], $result['items'][0]['value']);
        self::assertSame($items[1]['value'], $result['items'][1]['value']);
    }

    public function testNonPositiveTotalHasNoShares(): void
    {
        foreach (['0', '-1'] as $total) {
            $result = OverviewFigures::allocation([['key' => 'CASH', 'label' => 'Espèces', 'value' => $total]], DecimalValue::fromString($total));
            self::assertNull($result['items'][0]['share']);
            self::assertSame([], $result['slices']);
        }
    }
}
