<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Infrastructure;

use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\WorkbookUsageError;
use App\Module\Import\Infrastructure\Xlsx\XlsxWorkbookReader;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use PHPUnit\Framework\TestCase;

final class XlsxWorkbookReaderTest extends TestCase
{
    private WorkbookBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WorkbookBuilder();
    }

    protected function tearDown(): void
    {
        $this->builder->cleanUp();
    }

    public function testTheYearComesFromTheFileNameAndTheWholeWorkbookIsParsed(): void
    {
        $this->builder->monthSheet('Novembre')->text('A2', 'Salary')->number('B2', '2000');

        $workbook = (new XlsxWorkbookReader())->read($this->builder->save('2023 sample.xlsx'));

        self::assertSame(2023, $workbook->year);
        self::assertSame('wb:2023:11:INC:salary:1', $workbook->month(11)?->linesOf(Block::INC)[0]->movements[0]->referenceIn($workbook->year)->toString());
    }

    public function testAnExplicitYearOverridesTheFileName(): void
    {
        $this->builder->monthSheet('Janvier');

        self::assertSame(2030, (new XlsxWorkbookReader())->read($this->builder->save('2023.xlsx'), 2030)->year);
    }

    public function testAFileNameWithoutAYearIsAUsageError(): void
    {
        $this->builder->monthSheet('Janvier');

        $this->expectException(WorkbookUsageError::class);

        (new XlsxWorkbookReader())->read($this->builder->save('budget.xlsx'));
    }

    public function testAnArchiveControlFailureSurfacesAsARefusal(): void
    {
        $this->builder->monthSheet('Janvier')->text('A2', 'Salary');
        $this->builder->part('xl/sharedStrings.xml', '<!DOCTYPE x><sst/>');

        $this->expectException(ArchiveRefused::class);

        (new XlsxWorkbookReader())->read($this->builder->save('2025.xlsx'));
    }
}
