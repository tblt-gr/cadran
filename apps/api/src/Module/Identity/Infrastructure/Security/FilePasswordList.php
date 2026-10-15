<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Domain\CommonPasswordList;

/**
 * Common passwords read once from `common-passwords.txt` (source and commit in its header). The file is
 * lowercased, so the candidate is lowercased the same way and the match ignores case.
 */
final class FilePasswordList implements CommonPasswordList
{
    /** @var array<string, true>|null */
    private ?array $entries = null;

    public function __construct(private readonly string $path = __DIR__.'/common-passwords.txt')
    {
    }

    public function contains(string $candidate): bool
    {
        $this->entries ??= $this->load();

        return isset($this->entries[mb_strtolower($candidate)]);
    }

    /** @return array<string, true> */
    private function load(): array
    {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            throw new \RuntimeException('The common password list cannot be read.');
        }

        $entries = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '#')) {
                $entries[$line] = true;
            }
        }

        return $entries;
    }
}
