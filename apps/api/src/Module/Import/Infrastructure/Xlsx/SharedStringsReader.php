<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;

/** Reads `xl/sharedStrings.xml` into a list of plain strings. */
final class SharedStringsReader
{
    /**
     * @return list<string>
     */
    public static function read(string $xml): array
    {
        $strings = [];

        SafeXml::read($xml, static function (\XMLReader $reader) use (&$strings): void {
            $more = $reader->read();
            while ($more) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName) {
                    $node = $reader->expand();
                    if (!$node instanceof \DOMElement) {
                        throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
                    }
                    $strings[] = RichText::plainText($node);
                    $more = $reader->next();
                    continue;
                }
                $more = $reader->read();
            }
        });

        return $strings;
    }
}
