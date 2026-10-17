<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Xlsx;

use App\Module\Import\Domain\ArchiveRefusal;
use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\WorkbookUsageError;

/**
 * The zip container of a workbook, with every archive control applied before a
 * single byte of content is trusted: file size, entry count, entry names,
 * declared and streamed uncompressed size, compression ratio and, for each part
 * opened, the absence of `<!DOCTYPE`/`<!ENTITY`. Nothing is ever extracted to
 * disk: a part is read into memory by an exact entry name.
 */
final class XlsxArchive
{
    private const int CHUNK = 65536;

    /** @var array<string, array{size: int, compressed: int, index: int}> */
    private array $entries = [];

    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly ArchiveLimits $limits,
    ) {
    }

    public static function open(string $path, ArchiveLimits $limits): self
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new WorkbookUsageError('The PHP zip extension (ext-zip) is not available: run the import from the tooling container.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new WorkbookUsageError('The workbook file does not exist or is not readable.');
        }
        $size = filesize($path);
        if (false === $size || $size > $limits->maxFileBytes) {
            throw new ArchiveRefused(ArchiveRefusal::FILE_TOO_LARGE);
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw new ArchiveRefused(ArchiveRefusal::NOT_AN_ARCHIVE);
        }

        $archive = new self($zip, $limits);
        $archive->indexEntries();

        return $archive;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]) && !str_ends_with($name, '/');
    }

    /**
     * The decompressed bytes of one part, checked for hostile content.
     * The name must come from the package structure, never from user input.
     */
    public function readPart(string $name): string
    {
        if (!$this->has($name)) {
            throw new ArchiveRefused(ArchiveRefusal::MISSING_PART);
        }
        $entry = $this->entries[$name];

        $stream = $this->zip->getStream($name);
        if (false === $stream) {
            throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
        }

        // The declared sizes come from the archive itself and can lie: the bytes actually produced are counted too.
        $content = '';
        $total = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if (false === $chunk) {
                fclose($stream);
                throw new ArchiveRefused(ArchiveRefusal::MALFORMED_XML);
            }
            $total += strlen($chunk);
            if ($total > $this->limits->maxPartBytes || $total > $entry['size']) {
                fclose($stream);
                throw new ArchiveRefused(ArchiveRefusal::ENTRY_TOO_LARGE);
            }
            if ($total > $this->limits->maxCompressionRatio * max(1, $entry['compressed'])) {
                fclose($stream);
                throw new ArchiveRefused(ArchiveRefusal::COMPRESSION_RATIO);
            }
            $content .= $chunk;
        }
        fclose($stream);

        SafeXml::assertSafe($content);

        return $content;
    }

    private function indexEntries(): void
    {
        if ($this->zip->numFiles > $this->limits->maxEntries) {
            throw new ArchiveRefused(ArchiveRefusal::TOO_MANY_ENTRIES);
        }

        for ($index = 0; $index < $this->zip->numFiles; ++$index) {
            $stat = $this->zip->statIndex($index);
            if (false === $stat) {
                throw new ArchiveRefused(ArchiveRefusal::NOT_AN_ARCHIVE);
            }

            $name = $stat['name'];
            if (!self::isSafeName($name) || isset($this->entries[$name])) {
                throw new ArchiveRefused(ArchiveRefusal::UNSAFE_ENTRY_NAME);
            }
            if ($stat['size'] > $this->limits->maxPartBytes) {
                throw new ArchiveRefused(ArchiveRefusal::ENTRY_TOO_LARGE);
            }
            if ($stat['size'] > $this->limits->maxCompressionRatio * $stat['comp_size']) {
                throw new ArchiveRefused(ArchiveRefusal::COMPRESSION_RATIO);
            }

            $this->entries[$name] = ['size' => $stat['size'], 'compressed' => $stat['comp_size'], 'index' => $index];
        }
    }

    /** A relative name of plain segments: no traversal, no absolute path, no drive letter, no control character. */
    private static function isSafeName(string $name): bool
    {
        if ('' === $name || strlen($name) > 255 || 1 === preg_match('/[\x00-\x1F\x7F\\\\]/', $name)) {
            return false;
        }
        if (str_starts_with($name, '/') || 1 === preg_match('/^[A-Za-z]:/', $name)) {
            return false;
        }

        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }
        }

        return true;
    }
}
