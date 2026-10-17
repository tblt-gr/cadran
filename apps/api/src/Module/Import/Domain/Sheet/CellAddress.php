<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Sheet;

/** A1-style coordinates: column letters and a 1-based row. */
final readonly class CellAddress
{
    private const int MAX_ROW = 1048576;

    private function __construct(
        public string $column,
        public int $row,
    ) {
    }

    public static function of(string $column, int $row): self
    {
        if (1 !== preg_match('/^[A-Z]{1,3}$/D', $column) || $row < 1 || $row > self::MAX_ROW) {
            throw new \InvalidArgumentException('A cell address is one to three capital letters and a row from 1 to 1048576.');
        }

        return new self($column, $row);
    }

    public static function parse(string $reference): self
    {
        if (1 !== preg_match('/^([A-Za-z]{1,3})([1-9][0-9]*)$/D', $reference, $matches)) {
            throw new \InvalidArgumentException('Not a cell reference.');
        }

        return self::of(strtoupper($matches[1]), (int) $matches[2]);
    }

    public function toString(): string
    {
        return $this->column.$this->row;
    }

    /** The next column to the right, which is where a cell without an `r` attribute sits. */
    public function right(): self
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $letters = str_split($this->column);
        for ($index = count($letters) - 1; $index >= 0; --$index) {
            if ('Z' !== $letters[$index]) {
                $letters[$index] = $alphabet[(int) strpos($alphabet, $letters[$index]) + 1];

                return self::of(implode('', $letters), $this->row);
            }
            $letters[$index] = 'A';
        }

        return self::of('A'.implode('', $letters), $this->row);
    }
}
