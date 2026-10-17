<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Sheet\Cell;
use App\Module\Import\Domain\Sheet\CellAddress;
use App\Module\Import\Domain\Sheet\Worksheet;
use PHPUnit\Framework\TestCase;

final class WorksheetTest extends TestCase
{
    public function testAnAddressIsParsedAndFormatted(): void
    {
        $address = CellAddress::parse('b12');

        self::assertSame('B', $address->column);
        self::assertSame(12, $address->row);
        self::assertSame('B12', $address->toString());
    }

    public function testTheColumnToTheRightCarriesOver(): void
    {
        self::assertSame('C3', CellAddress::of('B', 3)->right()->toString());
        self::assertSame('AA3', CellAddress::of('Z', 3)->right()->toString());
        self::assertSame('AB3', CellAddress::of('AA', 3)->right()->toString());
    }

    public function testAMalformedReferenceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CellAddress::parse('1B');
    }

    public function testACellIsFoundByColumnAndRowAndTheLastUsedRowIsPerColumnPair(): void
    {
        $sheet = new Worksheet('Janvier');
        $sheet->put(CellAddress::parse('A2'), Cell::text('Salary'));
        $sheet->put(CellAddress::parse('B9'), Cell::number('100'));
        $sheet->put(CellAddress::parse('K20'), Cell::number('1'));

        self::assertSame('Salary', $sheet->cell('A', 2)?->value);
        self::assertNull($sheet->cell('A', 3));
        self::assertSame(9, $sheet->lastRow('A', 'B'));
        self::assertSame(0, $sheet->lastRow('C', 'D'));
    }
}
