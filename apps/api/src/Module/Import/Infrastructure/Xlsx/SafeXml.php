<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;

/**
 * XML hygiene for the parts of a workbook. The raw bytes are scanned before the
 * parser sees them: a `<!DOCTYPE` or `<!ENTITY` aborts, and only encodings in
 * which that scan is reliable are accepted (UTF-16 or UTF-7 could spell the
 * marker differently). The parser then runs without entity substitution, DTD
 * loading or network access.
 */
final class SafeXml
{
    public static function assertSafe(string $xml): void
    {
        if (str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF") || str_contains(substr($xml, 0, 8), "\0")) {
            throw new ArchiveRefused(ArchiveRefusal::UNSUPPORTED_ENCODING);
        }

        if (1 === preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*\sencoding\s*=\s*["\']([^"\']+)["\']/i', substr($xml, 0, 300), $matches)
            && !in_array(strtolower($matches[1]), ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            throw new ArchiveRefused(ArchiveRefusal::UNSUPPORTED_ENCODING);
        }

        if (false !== stripos($xml, '<!DOCTYPE') || false !== stripos($xml, '<!ENTITY')) {
            throw new ArchiveRefused(ArchiveRefusal::DOCTYPE_OR_ENTITY);
        }
    }

    /**
     * Reads the whole document through $visit, which receives the positioned
     * reader. A malformed document is refused, never repaired.
     *
     * @param callable(\XMLReader): void $visit
     */
    public static function read(string $xml, callable $visit): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $reader = new \XMLReader();
            if (!$reader->XML($xml, null, \LIBXML_NONET | \LIBXML_NOCDATA)) {
                throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
            }

            $visit($reader);
            // Drain the remainder so a late syntax error is still reported.
            while ($reader->read()) {
            }
            $reader->close();

            foreach (libxml_get_errors() as $error) {
                if ($error->level >= \LIBXML_ERR_ERROR) {
                    throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
