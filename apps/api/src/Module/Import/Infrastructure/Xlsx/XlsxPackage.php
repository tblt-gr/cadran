<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Sheet\SheetSource;
use App\Module\Import\Domain\Sheet\Worksheet;
use App\Module\Import\Domain\WorkbookUsageError;

/**
 * An opened `.xlsx` workbook. The only parts ever read are
 * `[Content_Types].xml`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, the
 * shared strings part and the worksheet parts of the sheets actually requested;
 * their entry names come from the relationships, never from user input. Styles
 * are not needed: amounts and labels carry no style-dependent meaning.
 */
final class XlsxPackage implements SheetSource
{
    private const string WORKBOOK_PART = 'xl/workbook.xml';
    private const string WORKBOOK_RELS_PART = 'xl/_rels/workbook.xml.rels';
    private const string CONTENT_TYPES_PART = '[Content_Types].xml';
    private const array RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];
    private const array WORKBOOK_CONTENT_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.template.main+xml',
        'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
    ];

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    /**
     * @param array<string, string>                                              $sheetRelationshipIds sheet name => relationship id
     * @param array<string, array{type: string, target: string, external: bool}> $relationships        relationship id => data
     */
    private function __construct(
        private readonly XlsxArchive $archive,
        private readonly ArchiveLimits $limits,
        private readonly array $sheetRelationshipIds,
        private readonly array $relationships,
    ) {
    }

    /**
     * @throws WorkbookUsageError for a file that cannot be used at all (missing, `.ods`, wrong extension)
     * @throws ArchiveRefused     for an archive that fails a security or well-formedness control
     */
    public static function open(string $path, ArchiveLimits $limits = new ArchiveLimits()): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ('ods' === $extension) {
            throw new WorkbookUsageError('Native .ods files are not read: convert the workbook first with `soffice --headless --convert-to xlsx <file>`.');
        }
        if ('xlsx' !== $extension) {
            throw new WorkbookUsageError('Only .xlsx workbooks are read.');
        }

        $archive = XlsxArchive::open($path, $limits);

        self::assertWorkbookContentType($archive);
        $sheets = self::readSheets($archive);
        $relationships = self::readRelationships($archive);

        return new self($archive, $limits, $sheets, $relationships);
    }

    public function sheetNames(): array
    {
        return array_keys($this->sheetRelationshipIds);
    }

    public function readSheet(string $name): Worksheet
    {
        if (!isset($this->sheetRelationshipIds[$name])) {
            throw new \InvalidArgumentException('The workbook has no sheet of that name.');
        }

        $relationship = $this->relationships[$this->sheetRelationshipIds[$name]] ?? null;
        if (null === $relationship || $relationship['external'] || !str_ends_with($relationship['type'], '/worksheet')) {
            throw new ArchiveRefused(ArchiveRefusal::UNSAFE_RELATIONSHIP);
        }

        $xml = $this->archive->readPart(self::resolve($relationship['target']));

        return WorksheetReader::read($xml, $name, $this->sharedStrings(), $this->limits->maxCellsPerSheet);
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(): array
    {
        if (null !== $this->sharedStrings) {
            return $this->sharedStrings;
        }

        $this->sharedStrings = [];
        foreach ($this->relationships as $relationship) {
            if (!$relationship['external'] && str_ends_with($relationship['type'], '/sharedStrings')) {
                $this->sharedStrings = SharedStringsReader::read($this->archive->readPart(self::resolve($relationship['target'])));
                break;
            }
        }

        return $this->sharedStrings;
    }

    private static function assertWorkbookContentType(XlsxArchive $archive): void
    {
        $declared = null;
        SafeXml::read($archive->readPart(self::CONTENT_TYPES_PART), static function (\XMLReader $reader) use (&$declared): void {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'Override' === $reader->localName && '/'.self::WORKBOOK_PART === $reader->getAttribute('PartName')) {
                    $declared = $reader->getAttribute('ContentType');
                }
            }
        });

        if (!in_array($declared, self::WORKBOOK_CONTENT_TYPES, true)) {
            throw new ArchiveRefused(ArchiveRefusal::UNSUPPORTED_CONTENT_TYPE);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function readSheets(XlsxArchive $archive): array
    {
        $sheets = [];
        SafeXml::read($archive->readPart(self::WORKBOOK_PART), static function (\XMLReader $reader) use (&$sheets): void {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT !== $reader->nodeType || 'sheet' !== $reader->localName) {
                    continue;
                }
                $name = $reader->getAttribute('name');
                $id = null;
                foreach (self::RELATIONSHIP_NAMESPACES as $namespace) {
                    $id ??= $reader->getAttributeNs('id', $namespace);
                }
                if (null === $name || '' === $name || null === $id || '' === $id || isset($sheets[$name])) {
                    throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
                }
                $sheets[$name] = $id;
            }
        });

        return $sheets;
    }

    /**
     * @return array<string, array{type: string, target: string, external: bool}>
     */
    private static function readRelationships(XlsxArchive $archive): array
    {
        $relationships = [];
        SafeXml::read($archive->readPart(self::WORKBOOK_RELS_PART), static function (\XMLReader $reader) use (&$relationships): void {
            while ($reader->read()) {
                if (\XMLReader::ELEMENT !== $reader->nodeType || 'Relationship' !== $reader->localName) {
                    continue;
                }
                $id = $reader->getAttribute('Id');
                $type = $reader->getAttribute('Type');
                $target = $reader->getAttribute('Target');
                if (null === $id || null === $type || null === $target) {
                    throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
                }
                $relationships[$id] = [
                    'type' => $type,
                    'target' => $target,
                    'external' => 'External' === $reader->getAttribute('TargetMode'),
                ];
            }
        });

        return $relationships;
    }

    /** Resolves a relationship target against `xl/` and refuses anything that leaves the package. */
    private static function resolve(string $target): string
    {
        if (str_contains($target, '\\') || str_contains($target, "\0")) {
            throw new ArchiveRefused(ArchiveRefusal::UNSAFE_RELATIONSHIP);
        }

        $segments = str_starts_with($target, '/') ? explode('/', $target) : ['xl', ...explode('/', $target)];
        $resolved = [];
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if ([] === $resolved) {
                    throw new ArchiveRefused(ArchiveRefusal::UNSAFE_RELATIONSHIP);
                }
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        if ([] === $resolved) {
            throw new ArchiveRefused(ArchiveRefusal::UNSAFE_RELATIONSHIP);
        }

        return implode('/', $resolved);
    }
}
