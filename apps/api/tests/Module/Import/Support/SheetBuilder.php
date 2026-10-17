<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

use App\Module\Import\Domain\Sheet\CellAddress;

/**
 * One worksheet of a {@see WorkbookBuilder}. Every method takes an A1 reference
 * and returns the builder, so a fixture reads as a list of cells.
 */
final class SheetBuilder
{
    /** @var array<int, array<int, string>> row => column index => cell xml */
    private array $cells = [];

    public function __construct(
        public readonly string $name,
        private readonly WorkbookBuilder $workbook,
    ) {
    }

    /** A text cell; shared string by default, inline when the workbook disables shared strings. */
    public function text(string $reference, string $value): self
    {
        if ($this->workbook->usesSharedStrings()) {
            return $this->raw($reference, sprintf('<c r="%s" t="s"><v>%d</v></c>', $reference, $this->workbook->sharedString($value)));
        }

        return $this->inline($reference, $value);
    }

    public function inline(string $reference, string $value): self
    {
        return $this->raw($reference, sprintf('<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $reference, self::escape($value)));
    }

    /** Inline rich text made of several runs, as Excel writes formatted labels. */
    public function inlineRuns(string $reference, string ...$runs): self
    {
        $xml = '';
        foreach ($runs as $run) {
            $xml .= sprintf('<r><t xml:space="preserve">%s</t></r>', self::escape($run));
        }
        $xml .= '<rPh sb="0" eb="1"><t>ignored phonetic</t></rPh>';

        return $this->raw($reference, sprintf('<c r="%s" t="inlineStr"><is>%s</is></c>', $reference, $xml));
    }

    /** A number stored exactly as the given text, the way `<v>` carries it. */
    public function number(string $reference, string $storedValue): self
    {
        return $this->raw($reference, sprintf('<c r="%s"><v>%s</v></c>', $reference, self::escape($storedValue)));
    }

    /** A numeric formula (text without the leading `=`) with its cached value. */
    public function formula(string $reference, string $formula, ?string $cached): self
    {
        $value = null === $cached ? '' : sprintf('<v>%s</v>', self::escape($cached));

        return $this->raw($reference, sprintf('<c r="%s"><f>%s</f>%s</c>', $reference, self::escape($formula), $value));
    }

    /** A formula whose result is a string (`t="str"`). */
    public function formulaString(string $reference, string $formula, string $cached): self
    {
        return $this->raw($reference, sprintf('<c r="%s" t="str"><f>%s</f><v>%s</v></c>', $reference, self::escape($formula), self::escape($cached)));
    }

    /** A formula `1+1` stored with no cached value, as a library that never recalculated writes it. */
    public function formulaWithoutValueCell(string $reference): self
    {
        return $this->formula($reference, '1+1', null);
    }

    public function error(string $reference, string $code): self
    {
        return $this->raw($reference, sprintf('<c r="%s" t="e"><v>%s</v></c>', $reference, self::escape($code)));
    }

    public function boolean(string $reference, bool $value): self
    {
        return $this->raw($reference, sprintf('<c r="%s" t="b"><v>%d</v></c>', $reference, $value ? 1 : 0));
    }

    /** The master of a shared formula: it alone carries the text. */
    public function sharedFormulaMaster(string $reference, string $range, int $index, string $formula, string $cached): self
    {
        return $this->raw($reference, sprintf('<c r="%s"><f t="shared" ref="%s" si="%d">%s</f><v>%s</v></c>', $reference, $range, $index, self::escape($formula), self::escape($cached)));
    }

    public function sharedFormulaChild(string $reference, int $index, string $cached): self
    {
        return $this->raw($reference, sprintf('<c r="%s"><f t="shared" si="%d"/><v>%s</v></c>', $reference, $index, self::escape($cached)));
    }

    /** A cell written verbatim, for shapes no other method produces. */
    public function raw(string $reference, string $cellXml): self
    {
        $address = CellAddress::parse($reference);
        $this->cells[$address->row][self::columnIndex($address->column)] = $cellXml;

        return $this;
    }

    public function toXml(): string
    {
        ksort($this->cells);
        $rows = '';
        foreach ($this->cells as $row => $cells) {
            ksort($cells);
            $rows .= sprintf('<row r="%d">%s</row>', $row, implode('', $cells));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
