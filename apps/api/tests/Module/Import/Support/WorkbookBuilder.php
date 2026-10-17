<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

/**
 * Writes minimal valid `.xlsx` files for the import tests: shared or inline
 * strings, formulas with cached values, errors and arbitrary sheet order (the
 * order of the `sheet()` calls). Labels in tests are neutral (`Salary`,
 * `Food`, `Rent`, `Savings A`); no real workbook ever enters the repository.
 *
 * ZipArchive writes to disk, so a fixture lives in a temporary file the test
 * removes with {@see self::cleanUp()}.
 */
final class WorkbookBuilder
{
    private const string MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const string REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const string WORKBOOK_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    /** @var list<SheetBuilder> */
    private array $sheets = [];

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<string, string> entry name => content, written after the standard parts and replacing them */
    private array $parts = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    private bool $sharedStringsEnabled = true;
    private string $workbookContentType = self::WORKBOOK_TYPE;
    private string $sheetTargetPrefix = 'worksheets/';

    public function sheet(string $name): SheetBuilder
    {
        $sheet = new SheetBuilder($name, $this);
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /** A month sheet with the validated header row 1 already written. */
    public function monthSheet(string $name): SheetBuilder
    {
        return $this->sheet($name)
            ->text('A1', 'Revenus')->number('B1', '0')
            ->text('C1', 'Dépenses Fixes')->number('D1', '0')
            ->text('E1', 'Dépenses Variables')->number('F1', '0')
            ->text('G1', 'Épargnes')->number('H1', '0')
            ->text('J1', 'Comptes')->text('K1', 'Solde N-1')->text('L1', 'Solde N');
    }

    public function withoutSharedStrings(): self
    {
        $this->sharedStringsEnabled = false;

        return $this;
    }

    /** Targets written as `/xl/worksheets/sheetN.xml` (package-absolute) as some writers do. */
    public function withAbsoluteSheetTargets(): self
    {
        $this->sheetTargetPrefix = '/xl/worksheets/';

        return $this;
    }

    public function withWorkbookContentType(string $contentType): self
    {
        $this->workbookContentType = $contentType;

        return $this;
    }

    /** Adds or replaces one archive entry verbatim. */
    public function part(string $entryName, string $content): self
    {
        $this->parts[$entryName] = $content;

        return $this;
    }

    public function usesSharedStrings(): bool
    {
        return $this->sharedStringsEnabled;
    }

    public function sharedString(string $value): int
    {
        $existing = array_search($value, $this->sharedStrings, true);
        if (false !== $existing) {
            return $existing;
        }
        $this->sharedStrings[] = $value;

        return count($this->sharedStrings) - 1;
    }

    /** Writes the workbook to a new temporary `.xlsx` file and returns its path. */
    public function save(string $fileName = 'workbook.xlsx'): string
    {
        $directory = sys_get_temp_dir().'/'.uniqid('cadran-import-', true);
        mkdir($directory, 0o700);
        $this->temporaryFiles[] = $directory;
        $path = $directory.'/'.$fileName;
        $this->temporaryFiles[] = $path;

        $this->writeTo($path);

        return $path;
    }

    public function cleanUp(): void
    {
        foreach (array_reverse($this->temporaryFiles) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    private function writeTo(string $path): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot create the fixture archive.');
        }

        $sheetsXml = '';
        $relationships = '';
        $overrides = sprintf('<Override PartName="/xl/workbook.xml" ContentType="%s"/>', $this->workbookContentType);
        $entries = [];
        foreach ($this->sheets as $index => $sheet) {
            $number = $index + 1;
            $sheetsXml .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', htmlspecialchars($sheet->name, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $number, $number);
            $relationships .= sprintf('<Relationship Id="rId%d" Type="%s/worksheet" Target="%ssheet%d.xml"/>', $number, self::REL_NS, $this->sheetTargetPrefix, $number);
            $overrides .= sprintf('<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $number);
            $entries['xl/worksheets/sheet'.$number.'.xml'] = $sheet->toXml();
        }

        $sharedXml = '';
        foreach ($this->sharedStrings as $string) {
            $sharedXml .= sprintf('<si><t xml:space="preserve">%s</t></si>', htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
        if ([] !== $this->sharedStrings) {
            $relationships .= sprintf('<Relationship Id="rIdShared" Type="%s/sharedStrings" Target="sharedStrings.xml"/>', self::REL_NS);
            $overrides .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
            $entries['xl/sharedStrings.xml'] = sprintf('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="%s" count="%d" uniqueCount="%d">%s</sst>', self::MAIN_NS, count($this->sharedStrings), count($this->sharedStrings), $sharedXml);
        }

        $entries['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'.$overrides.'</Types>';
        $entries['xl/workbook.xml'] = sprintf('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="%s" xmlns:r="%s"><sheets>%s</sheets></workbook>', self::MAIN_NS, self::REL_NS, $sheetsXml);
        $entries['xl/_rels/workbook.xml.rels'] = sprintf('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">%s</Relationships>', $relationships);

        foreach ([...$entries, ...$this->parts] as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }
}
