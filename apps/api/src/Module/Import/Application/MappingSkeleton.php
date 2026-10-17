<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Workbook\ParsedPortfolioSheet;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;

/**
 * Writes the mapping skeleton: every distinct label per block, account label,
 * savings target and portfolio column header found in the given workbooks, each
 * with a `null` value and a trailing comment naming the years and months where
 * it appears. Labels only ever appear as quoted keys; the comments hold years
 * and month numbers, and no amount is ever written.
 */
final class MappingSkeleton
{
    private const array SECTIONS = [
        'accounts' => null,
        'income' => Block::INC,
        'fixed' => Block::FIX,
        'variable' => Block::VAR,
        'savings' => Block::SAV,
    ];

    /**
     * @param list<ParsedWorkbook> $workbooks in any order: they are read chronologically
     */
    public function render(array $workbooks): string
    {
        usort($workbooks, static fn (ParsedWorkbook $a, ParsedWorkbook $b): int => $a->year <=> $b->year);

        $lines = [
            '# Cadran workbook import mapping: every value is null, fill each one in.',
            '# A comment lists the years and months where a label appears in the workbooks.',
            'version: 1',
            'main_account: null',
        ];

        foreach (self::SECTIONS as $section => $block) {
            $lines = [...$lines, ...$this->section($section, $this->labelOccurrences($workbooks, $block))];
        }

        $lines[] = 'portfolio:';
        foreach (['pea' => Block::PEA, 'cto_tr' => Block::CTO] as $key => $kind) {
            $occurrences = $this->instrumentOccurrences($workbooks, $kind);
            if (null === $occurrences) {
                $lines[] = '  '.$key.': null';
                continue;
            }
            $lines[] = '  '.$key.':';
            $lines[] = '    account: null';
            foreach ($this->section('instruments', $occurrences, '    ') as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'ignore: {}';
        $lines[] = 'overrides: {}';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, array{raw: string, years: array<int, array<int, true>>}> $occurrences
     *
     * @return list<string>
     */
    private function section(string $name, array $occurrences, string $indent = ''): array
    {
        if ([] === $occurrences) {
            return [$indent.$name.': {}'];
        }

        $lines = [$indent.$name.':'];
        foreach ($occurrences as $occurrence) {
            $lines[] = sprintf('%s  %s: null # %s', $indent, self::quote($occurrence['raw']), self::describe($occurrence['years']));
        }

        return $lines;
    }

    /**
     * @param list<ParsedWorkbook> $workbooks
     *
     * @return array<string, array{raw: string, years: array<int, array<int, true>>}>
     */
    private function labelOccurrences(array $workbooks, ?Block $block): array
    {
        $occurrences = [];
        foreach ($workbooks as $workbook) {
            foreach ($workbook->months as $month) {
                $labels = null === $block
                    ? array_map(static fn ($account): array => [$account->labelKey, $account->rawLabel], $month->accounts)
                    : array_map(static fn ($line): array => [$line->labelKey, $line->rawLabel], $month->linesOf($block));

                foreach ($labels as [$key, $raw]) {
                    $occurrences[$key]['raw'] ??= $raw;
                    $occurrences[$key]['years'][$workbook->year][$month->month] = true;
                }
            }
        }

        return $occurrences;
    }

    /**
     * @param list<ParsedWorkbook> $workbooks
     *
     * @return array<string, array{raw: string, years: array<int, array<int, true>>}>|null null when no workbook has the sheet
     */
    private function instrumentOccurrences(array $workbooks, Block $kind): ?array
    {
        $occurrences = null;
        foreach ($workbooks as $workbook) {
            $sheet = Block::PEA === $kind ? $workbook->pea : $workbook->ctoTr;
            if (!$sheet instanceof ParsedPortfolioSheet) {
                continue;
            }
            $occurrences ??= [];
            foreach ($sheet->instruments as $instrument) {
                $occurrences[$instrument->headerKey]['raw'] ??= $instrument->header;
                $occurrences[$instrument->headerKey]['years'][$workbook->year] ??= [];
                foreach ($sheet->rows as $row) {
                    foreach ($row->buys as $buy) {
                        if ($buy->labelKey === $instrument->column && null !== $row->month) {
                            $occurrences[$instrument->headerKey]['years'][$workbook->year][$row->month] = true;
                        }
                    }
                }
            }
        }

        return $occurrences;
    }

    /**
     * @param array<int, array<int, true>> $years
     */
    private static function describe(array $years): string
    {
        ksort($years);
        $parts = [];
        foreach ($years as $year => $months) {
            $numbers = array_keys($months);
            sort($numbers);
            $parts[] = [] === $numbers ? (string) $year : $year.': '.self::ranges($numbers);
        }

        return implode('; ', $parts);
    }

    /**
     * @param list<int> $numbers ascending
     */
    private static function ranges(array $numbers): string
    {
        $ranges = [];
        $start = $previous = $numbers[0];
        foreach (array_slice($numbers, 1) as $number) {
            if ($number === $previous + 1) {
                $previous = $number;
                continue;
            }
            $ranges[] = self::range($start, $previous);
            $start = $previous = $number;
        }
        $ranges[] = self::range($start, $previous);

        return implode(', ', $ranges);
    }

    private static function range(int $start, int $end): string
    {
        return $start === $end ? (string) $start : $start.'-'.$end;
    }

    /** A JSON string is a valid YAML double-quoted scalar and escapes every control character. */
    private static function quote(string $label): string
    {
        return (string) json_encode($label, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
