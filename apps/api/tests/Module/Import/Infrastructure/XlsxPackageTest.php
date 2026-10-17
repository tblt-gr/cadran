<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Infrastructure;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Sheet\Cell;
use App\Module\Import\Domain\Sheet\CellKind;
use App\Module\Import\Domain\Sheet\Worksheet;
use App\Module\Import\Domain\WorkbookUsageError;
use App\Module\Import\Infrastructure\Xlsx\ArchiveLimits;
use App\Module\Import\Infrastructure\Xlsx\XlsxPackage;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class XlsxPackageTest extends TestCase
{
    private WorkbookBuilder $builder;

    private static function cell(Worksheet $sheet, string $column, int $row): Cell
    {
        $cell = $sheet->cell($column, $row);
        self::assertNotNull($cell, 'cell expected');

        return $cell;
    }

    protected function setUp(): void
    {
        $this->builder = new WorkbookBuilder();
    }

    protected function tearDown(): void
    {
        $this->builder->cleanUp();
    }

    public function testSheetsAreListedInWorkbookOrderWhateverTheirNames(): void
    {
        $this->builder->sheet('Mars');
        $this->builder->sheet('Récap & Annuel');
        $this->builder->sheet('Janvier');

        $package = XlsxPackage::open($this->builder->save());

        self::assertSame(['Mars', 'Récap & Annuel', 'Janvier'], $package->sheetNames());
    }

    public function testEveryCellKindIsReadWithItsStoredText(): void
    {
        $this->builder->sheet('Janvier')
            ->text('A1', 'Salary')
            ->inline('A2', 'Food')
            ->inlineRuns('A3', 'Sav', 'ings A')
            ->number('B1', '90.4899999999998')
            ->formula('B2', '12.5+48.2+7.9', '68.6')
            ->formulaString('A4', 'A1', 'Salary')
            ->error('B3', '#DIV/0!')
            ->boolean('B4', true)
            ->formulaWithoutValueCell('B5');

        $sheet = XlsxPackage::open($this->builder->save())->readSheet('Janvier');

        self::assertSame(CellKind::TEXT, self::cell($sheet, 'A', 1)->kind);
        self::assertSame('Salary', self::cell($sheet, 'A', 1)->value);
        self::assertSame('Food', self::cell($sheet, 'A', 2)->value);
        self::assertSame('Savings A', self::cell($sheet, 'A', 3)->value, 'phonetic runs are ignored, runs are joined');
        self::assertSame('90.4899999999998', self::cell($sheet, 'B', 1)->value);
        self::assertSame('12.5+48.2+7.9', self::cell($sheet, 'B', 2)->formula);
        self::assertSame('68.6', self::cell($sheet, 'B', 2)->value);
        self::assertSame(CellKind::TEXT, self::cell($sheet, 'A', 4)->kind);
        self::assertSame('A1', self::cell($sheet, 'A', 4)->formula);
        self::assertSame(CellKind::ERROR, self::cell($sheet, 'B', 3)->kind);
        self::assertSame('#DIV/0!', self::cell($sheet, 'B', 3)->value);
        self::assertSame(CellKind::BOOLEAN, self::cell($sheet, 'B', 4)->kind);
        self::assertNull(self::cell($sheet, 'B', 5)->value);
        self::assertSame('1+1', self::cell($sheet, 'B', 5)->formula);
    }

    public function testInlineStringsAreEnoughWhenThereIsNoSharedStringsPart(): void
    {
        $this->builder->withoutSharedStrings()->sheet('Janvier')->text('A1', 'Salary');

        $sheet = XlsxPackage::open($this->builder->save())->readSheet('Janvier');

        self::assertSame('Salary', self::cell($sheet, 'A', 1)->value);
    }

    public function testASharedFormulaChildCarriesNoTextOfItsOwn(): void
    {
        $this->builder->sheet('Janvier')
            ->sharedFormulaMaster('L2', 'L2:L3', 0, 'K2+1', '11')
            ->sharedFormulaChild('L3', 0, '12');

        $sheet = XlsxPackage::open($this->builder->save())->readSheet('Janvier');

        self::assertSame('K2+1', self::cell($sheet, 'L', 2)->formula);
        self::assertFalse(self::cell($sheet, 'L', 2)->sharedFormulaChild);
        self::assertNull(self::cell($sheet, 'L', 3)->formula);
        self::assertTrue(self::cell($sheet, 'L', 3)->sharedFormulaChild);
        self::assertSame('12', self::cell($sheet, 'L', 3)->value);
    }

    public function testPackageAbsoluteSheetTargetsAreResolved(): void
    {
        $this->builder->withAbsoluteSheetTargets()->sheet('Janvier')->number('B2', '5');

        self::assertSame('5', XlsxPackage::open($this->builder->save())->readSheet('Janvier')->cell('B', 2)?->value);
    }

    public function testOnlyTheRequestedSheetPartIsOpened(): void
    {
        $this->builder->sheet('Janvier')->number('B2', '5');
        $this->builder->sheet('Recap');
        // The recap sheet is never read, so its content is never even parsed.
        $this->builder->part('xl/worksheets/sheet2.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><worksheet/>');

        $package = XlsxPackage::open($this->builder->save());

        self::assertSame('5', $package->readSheet('Janvier')->cell('B', 2)?->value);
    }

    public function testAnUnlistedPartIsNeverOpened(): void
    {
        $this->builder->sheet('Janvier')->number('B2', '5');
        $this->builder->part('xl/drawings/drawing1.xml', '<!DOCTYPE x><x/>');

        self::assertSame('5', XlsxPackage::open($this->builder->save())->readSheet('Janvier')->cell('B', 2)?->value);
    }

    public function testASheetThatIsNotInTheWorkbookIsAUsageBug(): void
    {
        $this->builder->sheet('Janvier');

        $this->expectException(\InvalidArgumentException::class);

        XlsxPackage::open($this->builder->save())->readSheet('Février');
    }

    #[DataProvider('hostileXmlParts')]
    public function testADoctypeOrEntityInAnyOpenedPartAbortsTheArchive(string $part, string $content): void
    {
        $this->builder->sheet('Janvier')->text('A1', 'Salary');
        $this->builder->part($part, $content);

        $this->expectRefusal(ArchiveRefusal::DOCTYPE_OR_ENTITY, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileXmlParts(): iterable
    {
        $header = '<?xml version="1.0" encoding="UTF-8"?>';
        $xxe = $header.'<!DOCTYPE sst [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&xxe;</t></si></sst>';

        yield 'shared strings with an external entity' => ['xl/sharedStrings.xml', $xxe];
        yield 'worksheet with an internal entity (billion laughs)' => ['xl/worksheets/sheet1.xml', $header.'<!DOCTYPE lolz [<!ENTITY lol "lol">]><worksheet/>'];
        yield 'worksheet with a lowercase doctype' => ['xl/worksheets/sheet1.xml', $header.'<!doctype worksheet><worksheet/>'];
        yield 'workbook' => ['xl/workbook.xml', $header.'<!DOCTYPE workbook><workbook/>'];
        yield 'relationships' => ['xl/_rels/workbook.xml.rels', $header.'<!DOCTYPE r><Relationships/>'];
        yield 'content types' => ['[Content_Types].xml', $header.'<!DOCTYPE t><Types/>'];
    }

    #[DataProvider('hiddenEncodings')]
    public function testAPartInAnEncodingThatCouldHideADoctypeIsRefused(string $content): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part('xl/worksheets/sheet1.xml', $content);

        $this->expectRefusal(ArchiveRefusal::UNSUPPORTED_ENCODING, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hiddenEncodings(): iterable
    {
        yield 'utf-16 with a byte order mark' => ["\xFF\xFE<\0w\0/\0>\0"];
        yield 'utf-16 without a byte order mark' => ["<\0w\0o\0r\0k\0"];
        yield 'declared utf-7' => ['<?xml version="1.0" encoding="UTF-7"?><worksheet/>'];
        yield 'declared utf-16' => ['<?xml version="1.0" encoding="utf-16"?><worksheet/>'];
    }

    public function testMalformedXmlIsRefused(): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row></sheetData>');

        $this->expectRefusal(ArchiveRefusal::MALFORMED_XML, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    public function testAFileAboveTheSizeLimitIsRefusedBeforeBeingOpened(): void
    {
        $this->builder->sheet('Janvier');
        $path = $this->builder->save();

        $this->expectRefusal(ArchiveRefusal::FILE_TOO_LARGE, fn () => XlsxPackage::open($path, new ArchiveLimits(maxFileBytes: 100)));
    }

    public function testAnArchiveWithTooManyEntriesIsRefused(): void
    {
        $this->builder->sheet('Janvier');
        for ($i = 0; $i < 513; ++$i) {
            $this->builder->part(sprintf('xl/media/image%d.bin', $i), 'x');
        }

        $this->expectRefusal(ArchiveRefusal::TOO_MANY_ENTRIES, fn () => XlsxPackage::open($this->builder->save()));
    }

    public function testAWorkbookWithManyChartPartsIsAccepted(): void
    {
        $this->builder->sheet('Janvier');
        for ($i = 1; $i <= 40; ++$i) {
            foreach (['chart', 'style', 'colors'] as $kind) {
                $this->builder->part(sprintf('xl/charts/%s%d.xml', $kind, $i), '<x/>');
            }
            $this->builder->part(sprintf('xl/charts/_rels/chart%d.xml.rels', $i), '<x/>');
        }

        self::assertInstanceOf(XlsxPackage::class, XlsxPackage::open($this->builder->save()));
    }

    public function testTheDefaultEntryLimitIsFiveHundredTwelve(): void
    {
        $limits = new ArchiveLimits();

        self::assertSame(512, $limits->maxEntries);
        self::assertSame(20 * 1024 * 1024, $limits->maxFileBytes);
        self::assertSame(50 * 1024 * 1024, $limits->maxPartBytes);
        self::assertSame(100, $limits->maxCompressionRatio);
    }

    public function testAPartAboveTheUncompressedLimitIsRefused(): void
    {
        $this->builder->sheet('Janvier')->number('B2', str_repeat('7', 5000));

        $path = $this->builder->save();

        $this->expectRefusal(ArchiveRefusal::ENTRY_TOO_LARGE, fn () => XlsxPackage::open($path, new ArchiveLimits(maxPartBytes: 4000)));
    }

    public function testAZipBombIsRefusedByItsCompressionRatio(): void
    {
        $this->builder->sheet('Janvier');
        // 3 MB of zeros deflate to a few kilobytes: a ratio far above 100.
        $this->builder->part('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet><sheetData>'.str_repeat(' ', 3 * 1024 * 1024).'</sheetData></worksheet>');

        $this->expectRefusal(ArchiveRefusal::COMPRESSION_RATIO, fn () => XlsxPackage::open($this->builder->save()));
    }

    public function testAnArchiveThatUnderstatesAPartSizeIsStoppedWhileStreaming(): void
    {
        $sheet = $this->builder->sheet('Janvier');
        for ($row = 1; $row <= 3000; ++$row) {
            $sheet->number('B'.$row, (string) ($row * 7919));
        }
        $path = $this->builder->save();

        // Lower the uncompressed size declared by the central directory entry of the sheet part.
        $bytes = (string) file_get_contents($path);
        $directory = strpos($bytes, "PK\x01\x02");
        while (false !== $directory && !str_contains(substr($bytes, $directory + 46, 30), 'sheet1.xml')) {
            $directory = strpos($bytes, "PK\x01\x02", $directory + 4);
        }
        self::assertNotFalse($directory);
        $bytes = substr_replace($bytes, pack('V', 1000), $directory + 24, 4);
        file_put_contents($path, $bytes);

        $package = XlsxPackage::open($path);

        try {
            $package->readSheet('Janvier');
            self::fail('The part must be refused.');
        } catch (ArchiveRefused $refused) {
            self::assertContains($refused->cause, [ArchiveRefusal::ENTRY_TOO_LARGE, ArchiveRefusal::MALFORMED_XML]);
        }
    }

    #[DataProvider('unsafeEntryNames')]
    public function testAnEntryNameThatCouldEscapeTheArchiveIsRefused(string $name): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part($name, 'x');

        $this->expectRefusal(ArchiveRefusal::UNSAFE_ENTRY_NAME, fn () => XlsxPackage::open($this->builder->save()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeEntryNames(): iterable
    {
        yield 'parent segment' => ['xl/../../evil.xml'];
        yield 'leading parent segment' => ['../evil.xml'];
        yield 'absolute name' => ['/etc/evil.xml'];
        yield 'backslash separator' => ['xl\\..\\evil.xml'];
        yield 'dot segment' => ['xl/./sheet.xml'];
        yield 'drive letter' => ['C:/evil.xml'];
    }

    public function testARelationshipTargetThatLeavesThePackageIsRefused(): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="../../../etc/passwd"/></Relationships>');

        $this->expectRefusal(ArchiveRefusal::UNSAFE_RELATIONSHIP, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    public function testARelationshipToAnExternalTargetIsNeverFollowed(): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="https://example.invalid/sheet.xml" TargetMode="External"/></Relationships>');

        $this->expectRefusal(ArchiveRefusal::UNSAFE_RELATIONSHIP, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    public function testARelationshipToAMissingPartIsRefused(): void
    {
        $this->builder->sheet('Janvier');
        $this->builder->part('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/absent.xml"/></Relationships>');

        $this->expectRefusal(ArchiveRefusal::MISSING_PART, fn () => XlsxPackage::open($this->builder->save())->readSheet('Janvier'));
    }

    public function testAnArchiveWithoutAWorkbookPartIsRefused(): void
    {
        $path = $this->builder->save();
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->close();

        $this->expectRefusal(ArchiveRefusal::MISSING_PART, fn () => XlsxPackage::open($path));
    }

    public function testAWorkbookThatIsNotASpreadsheetIsRefusedByItsContentType(): void
    {
        $this->builder->withWorkbookContentType('application/vnd.ms-word')->sheet('Janvier');

        $this->expectRefusal(ArchiveRefusal::UNSUPPORTED_CONTENT_TYPE, fn () => XlsxPackage::open($this->builder->save()));
    }

    public function testAFileThatIsNotAZipIsRefused(): void
    {
        $path = $this->builder->save();
        file_put_contents($path, 'plain text, not an archive');

        $this->expectRefusal(ArchiveRefusal::NOT_AN_ARCHIVE, fn () => XlsxPackage::open($path));
    }

    public function testASheetWithMoreCellsThanTheLimitIsRefused(): void
    {
        $sheet = $this->builder->sheet('Janvier');
        foreach (['A', 'B', 'C', 'D'] as $column) {
            $sheet->number($column.'1', '1');
        }

        $package = XlsxPackage::open($this->builder->save(), new ArchiveLimits(maxCellsPerSheet: 3));

        $this->expectRefusal(ArchiveRefusal::TOO_MANY_CELLS, fn () => $package->readSheet('Janvier'));
    }

    public function testAMissingFileIsAUsageErrorNotARejection(): void
    {
        $this->expectException(WorkbookUsageError::class);

        XlsxPackage::open('/nonexistent/2025.xlsx');
    }

    public function testAnOdsFileIsRefusedWithTheConversionCommand(): void
    {
        $path = $this->builder->save('2025.ods');

        try {
            XlsxPackage::open($path);
            self::fail('An .ods file must be refused.');
        } catch (WorkbookUsageError $error) {
            self::assertStringContainsString('soffice --headless --convert-to xlsx', $error->getMessage());
        }
    }

    public function testAnotherExtensionIsAUsageError(): void
    {
        $path = $this->builder->save('2025.csv');

        $this->expectException(WorkbookUsageError::class);

        XlsxPackage::open($path);
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectRefusal(ArchiveRefusal $cause, callable $action): void
    {
        try {
            $action();
            self::fail('The archive must be refused.');
        } catch (ArchiveRefused $refused) {
            self::assertSame($cause, $refused->cause);
        }
    }
}
