<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Sheet\Cell;
use App\Module\Import\Domain\Sheet\CellAddress;
use App\Module\Import\Domain\Sheet\CellKind;
use App\Module\Import\Domain\Sheet\Worksheet;

/**
 * Streams a worksheet part and keeps the cells that hold a value or a formula.
 * Numbers stay as the text stored in `<v>`; formula text is kept verbatim and
 * is only ever read by the constant grammar.
 */
final class WorksheetReader
{
    /**
     * @param list<string> $sharedStrings
     */
    public static function read(string $xml, string $name, array $sharedStrings, int $maxCells): Worksheet
    {
        $sheet = new Worksheet($name);
        $count = 0;

        SafeXml::read($xml, static function (\XMLReader $reader) use ($sheet, $sharedStrings, $maxCells, &$count): void {
            $row = 0;
            $previous = null;

            $more = $reader->read();
            while ($more) {
                if (\XMLReader::ELEMENT !== $reader->nodeType) {
                    $more = $reader->read();
                    continue;
                }

                if ('row' === $reader->localName) {
                    $declared = $reader->getAttribute('r');
                    $row = null !== $declared && ctype_digit($declared) ? (int) $declared : $row + 1;
                    $previous = null;
                } elseif ('c' === $reader->localName) {
                    $node = $reader->expand();
                    if (!$node instanceof \DOMElement) {
                        throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
                    }
                    $address = self::addressOf($node, $row, $previous);
                    $previous = $address;

                    $cell = self::cell($node, $sharedStrings);
                    if (null !== $cell) {
                        if (++$count > $maxCells) {
                            throw new ArchiveRefused(ArchiveRefusal::TOO_MANY_CELLS);
                        }
                        $sheet->put($address, $cell);
                    }
                    $more = $reader->next();
                    continue;
                }
                $more = $reader->read();
            }
        });

        return $sheet;
    }

    private static function addressOf(\DOMElement $node, int $row, ?CellAddress $previous): CellAddress
    {
        try {
            if ($node->hasAttribute('r')) {
                return CellAddress::parse($node->getAttribute('r'));
            }

            return $previous?->right() ?? CellAddress::of('A', max(1, $row));
        } catch (\InvalidArgumentException) {
            throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
        }
    }

    /**
     * @param list<string> $sharedStrings
     */
    private static function cell(\DOMElement $node, array $sharedStrings): ?Cell
    {
        $type = $node->getAttribute('t');
        $formula = null;
        $sharedChild = false;
        $value = null;
        $inline = null;

        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'f':
                    $text = trim($child->textContent);
                    if ('shared' === $child->getAttribute('t') && '' === $text) {
                        $sharedChild = true;
                    } else {
                        $formula = $text;
                    }
                    break;
                case 'v':
                    $value = $child->textContent;
                    break;
                case 'is':
                    $inline = RichText::plainText($child);
                    break;
            }
        }

        if ('inlineStr' === $type) {
            $value = $inline;
        }
        if (null === $value && null === $formula && !$sharedChild) {
            return null;
        }

        if ($sharedChild) {
            return new Cell(CellKind::NUMBER, null === $value ? null : trim($value), null, true);
        }

        return match ($type) {
            's' => Cell::text(self::sharedString($sharedStrings, $value), $formula),
            'inlineStr', 'str', 'd' => null === $value ? null : Cell::text($value, $formula),
            'b' => new Cell(CellKind::BOOLEAN, null === $value ? null : trim($value), $formula),
            'e' => new Cell(CellKind::ERROR, null === $value ? null : trim($value), $formula),
            default => new Cell(CellKind::NUMBER, null === $value ? null : trim($value), $formula),
        };
    }

    /**
     * @param list<string> $sharedStrings
     */
    private static function sharedString(array $sharedStrings, ?string $index): string
    {
        if (null === $index || !ctype_digit(trim($index)) || !isset($sharedStrings[(int) $index])) {
            throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
        }

        return $sharedStrings[(int) $index];
    }
}
