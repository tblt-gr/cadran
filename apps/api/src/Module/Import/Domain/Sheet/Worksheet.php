<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Sheet;

/** The cells of one sheet that hold a value or a formula, indexed by row then column. */
final class Worksheet
{
    /** @var array<int, array<string, Cell>> */
    private array $rows = [];

    public function __construct(public readonly string $name)
    {
    }

    public function put(CellAddress $address, Cell $cell): void
    {
        $this->rows[$address->row][$address->column] = $cell;
    }

    public function cell(string $column, int $row): ?Cell
    {
        return $this->rows[$row][$column] ?? null;
    }

    /**
     * The rows, in ascending order, holding a cell in any of the given columns.
     *
     * @return list<int>
     */
    public function rowsWith(string ...$columns): array
    {
        $rows = [];
        foreach ($this->rows as $row => $cells) {
            foreach ($columns as $column) {
                if (isset($cells[$column])) {
                    $rows[] = $row;
                    break;
                }
            }
        }
        sort($rows);

        return $rows;
    }

    /** The highest row holding a cell in any of the given columns, or 0. */
    public function lastRow(string ...$columns): int
    {
        $last = 0;
        foreach ($this->rows as $row => $cells) {
            foreach ($columns as $column) {
                if (isset($cells[$column])) {
                    $last = max($last, $row);
                    break;
                }
            }
        }

        return $last;
    }
}
