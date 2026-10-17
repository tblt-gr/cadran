<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\Amount\AmountCellReader;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\LabelKey;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Sheet\Worksheet;

/**
 * Reads the PEA sheet (one instrument column) or the CTO_TR sheet (up to four).
 * Row 2 holds the headers. The layout is anchored on the first "Janvier" in
 * column A below the headers: the row just above it is the opening state
 * (previous December), the twelve rows from it are the months matched by name,
 * and whatever follows is the recap that is never read. The standard sheet has
 * its opening on row 3 and its months on rows 4 to 15; a sheet shifted by a row
 * or two reads the same way. Gains, rates and notes are derived and ignored.
 */
final class PortfolioSheetReader
{
    private const int HEADER_ROW = 2;
    private const int STANDARD_JANUARY_ROW = 4;

    public function __construct(private readonly AmountCellReader $amounts = new AmountCellReader())
    {
    }

    /**
     * @param list<string> $instrumentColumns the purchase columns, left to right
     */
    public function read(Worksheet $sheet, Block $kind, array $instrumentColumns, string $securitiesColumn, int $precision, Findings $findings): ParsedPortfolioSheet
    {
        $dataColumns = ['A', 'B', 'C', ...$instrumentColumns, $securitiesColumn];
        $instruments = $this->readInstruments($sheet, $instrumentColumns, $findings);
        $headers = [];
        foreach ($instruments as $instrument) {
            $headers[$instrument->column] = $instrument;
        }

        $january = $this->januaryRow($sheet, $dataColumns, $findings);
        $openingRow = null === $january ? null : $january - 1;
        $lastMonthRow = ($january ?? self::STANDARD_JANUARY_ROW) + 11;

        $rows = [];
        $seenMonths = [];
        foreach ($sheet->rowsWith(...$dataColumns) as $row) {
            if (null === $january || $row <= self::HEADER_ROW || $row > $lastMonthRow) {
                continue;
            }
            if ($row < $openingRow) {
                // Values above the opening row belong to no month: they are never read, and never silent.
                $this->reportStrayRow($sheet, $row, $dataColumns, $findings);
                continue;
            }

            $month = null;
            if ($openingRow !== $row) {
                $month = $this->monthOfRow($sheet, $row, $dataColumns, $seenMonths, $findings);
                if (null === $month) {
                    continue;
                }
                $seenMonths[$month] = true;
            }

            // The opening row only matters when the planner builds the opening state: its problems wait on the row until then.
            $rowFindings = null === $month ? new Findings() : $findings;
            $parsed = $this->readRow($sheet, $kind, $row, $month, $headers, $securitiesColumn, $precision, $rowFindings);
            $rows[] = null === $month
                ? new PortfolioRow($parsed->row, null, $parsed->cash, $parsed->deposits, $parsed->securities, $parsed->buys, $rowFindings->issues(), $rowFindings->warnings())
                : $parsed;
        }

        return new ParsedPortfolioSheet($kind, $sheet->name, $instruments, $rows);
    }

    /**
     * @param list<string> $columns
     *
     * @return list<PortfolioInstrumentColumn>
     */
    private function readInstruments(Worksheet $sheet, array $columns, Findings $findings): array
    {
        $lastRow = ($this->januaryRow($sheet, ['A', ...$columns]) ?? self::STANDARD_JANUARY_ROW) + 11;
        $instruments = [];
        foreach ($columns as $column) {
            $header = $sheet->cell($column, self::HEADER_ROW)?->labelText();
            $key = null === $header ? '' : LabelKey::normalize($header);
            if (null !== $header && '' !== $key) {
                $instruments[] = new PortfolioInstrumentColumn($column, $header, $key);
                continue;
            }

            // A purchase under a column nobody named cannot be attributed to an instrument.
            foreach ($sheet->rowsWith($column) as $row) {
                if ($row > self::HEADER_ROW && $row <= $lastRow) {
                    $findings->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, $column.$row);
                    break;
                }
            }
        }

        if ([] === $instruments) {
            $findings->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, $columns[0].self::HEADER_ROW);
        }

        return $instruments;
    }

    /**
     * The first row below the headers whose column A is "janvier". No such row
     * while data rows exist is an unknown layout (reported when `$findings` is
     * given), and so is a January with no room for the opening row above it.
     *
     * @param list<string> $dataColumns
     */
    private function januaryRow(Worksheet $sheet, array $dataColumns, ?Findings $findings = null): ?int
    {
        $firstData = null;
        foreach ($sheet->rowsWith(...$dataColumns) as $row) {
            if ($row <= self::HEADER_ROW) {
                continue;
            }
            $firstData ??= $row;
            $name = $sheet->cell('A', $row)?->labelText();
            if (null !== $name && 1 === WorkbookLayoutReader::monthOfName(LabelKey::normalize($name))) {
                if ($row - 1 <= self::HEADER_ROW) {
                    $findings?->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, 'A'.$row);

                    return null;
                }

                return $row;
            }
        }
        if (null !== $firstData) {
            $findings?->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, 'A'.$firstData);
        }

        return null;
    }

    /**
     * @param list<string> $dataColumns
     */
    private function reportStrayRow(Worksheet $sheet, int $row, array $dataColumns, Findings $findings): void
    {
        foreach ($dataColumns as $column) {
            if (null !== $sheet->cell($column, $row)) {
                $findings->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, 'A'.$row);

                return;
            }
        }
    }

    /**
     * @param list<string>     $dataColumns
     * @param array<int, true> $seenMonths
     */
    private function monthOfRow(Worksheet $sheet, int $row, array $dataColumns, array $seenMonths, Findings $findings): ?int
    {
        $name = $sheet->cell('A', $row)?->labelText();
        $month = null === $name ? null : WorkbookLayoutReader::monthOfName(LabelKey::normalize($name));

        if (null !== $month && !isset($seenMonths[$month])) {
            return $month;
        }

        $carriesValues = null !== $month; // a repeated month name always carries the ambiguity
        foreach (array_slice($dataColumns, 1) as $column) {
            $carriesValues = $carriesValues || null !== $sheet->cell($column, $row);
        }
        if ($carriesValues) {
            $findings->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, 'A'.$row);
        }

        return null;
    }

    /**
     * @param array<string, PortfolioInstrumentColumn> $headers
     */
    private function readRow(
        Worksheet $sheet,
        Block $kind,
        int $row,
        ?int $month,
        array $headers,
        string $securitiesColumn,
        int $precision,
        Findings $findings,
    ): PortfolioRow {
        $values = [];
        foreach (['B' => 'cash', 'C' => 'deposits', $securitiesColumn => 'securities'] as $column => $name) {
            // Cash and the securities value feed the total valuation: a market value legitimately has more decimals than the cash asset.
            $reading = 'deposits' === $name
                ? $this->amounts->readBalance($sheet->cell($column, $row), $precision)
                : $this->amounts->readValuation($sheet->cell($column, $row), $precision);
            if (null !== $reading->rejection) {
                $findings->add($reading->rejection, $sheet->name, $column.$row);
            }
            if ($reading->floatArtifact) {
                $findings->warnFloatArtifact($sheet->name, $column.$row);
            }
            if ($reading->rounded) {
                $findings->warnValueRounded($sheet->name, $column.$row);
            }
            $values[$name] = $reading->value;
        }

        $buys = [];
        foreach ($headers as $column => $instrument) {
            $reading = $this->amounts->read($sheet->cell($column, $row), $precision, true);
            $cell = $column.$row;
            if ($reading->empty) {
                continue;
            }
            if (null !== $reading->rejection) {
                $findings->add($reading->rejection, $sheet->name, $cell);
                continue;
            }
            foreach ($reading->excluded as $excluded) {
                $findings->add($excluded, $sheet->name, $cell);
            }
            if ($reading->floatArtifact) {
                $findings->warnFloatArtifact($sheet->name, $cell);
            }
            foreach ($reading->terms as $index => $term) {
                $buys[] = new ParsedMovement($kind, $instrument->header, $column, $month, $sheet->name, $cell, $index + 1, $term->value, $term->isProduct, $term->factors);
            }
        }

        return new PortfolioRow($row, $month, self::value($values, 'cash'), self::value($values, 'deposits'), self::value($values, 'securities'), $buys);
    }

    /**
     * @param array<string, ?DecimalValue> $values
     */
    private static function value(array $values, string $name): ?DecimalValue
    {
        return $values[$name] ?? null;
    }
}
