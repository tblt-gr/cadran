<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Amount\AmountCellReader;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\LabelKey;
use App\Module\Import\Domain\Mapping\MonthCellOverrides;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Sheet\Worksheet;

/**
 * Reads one month sheet: the validated header, the four movement blocks and the
 * account table. Every other cell (totals, net worth, rates, goals) is derived
 * and never read.
 */
final class MonthSheetReader
{
    /** Block headers (row 1) only have to start with their normalized text; the account table headers match it exactly. */
    private const array PREFIX_HEADERS = ['A' => 'revenus', 'C' => 'depenses-fixes', 'E' => 'depenses-variables', 'G' => 'epargnes'];
    private const array EXACT_HEADERS = ['J' => 'comptes', 'K' => 'solde-n-1', 'L' => 'solde-n'];

    /** Label column, amount column and block of each movement block. */
    private const array BLOCKS = [
        ['A', 'B', Block::INC],
        ['C', 'D', Block::FIX],
        ['E', 'F', Block::VAR],
        ['G', 'H', Block::SAV],
    ];

    private const int FIRST_ACCOUNT_ROW = 2;
    private const int LAST_ACCOUNT_ROW = 10;

    public function __construct(private readonly AmountCellReader $amounts = new AmountCellReader())
    {
    }

    public function read(Worksheet $sheet, int $month, int $precision, Findings $findings, MonthCellOverrides $overrides = new MonthCellOverrides()): ?ParsedMonth
    {
        if (!$this->hasExpectedHeader($sheet, $findings)) {
            return null;
        }

        $lines = [];
        foreach (self::BLOCKS as [$labelColumn, $amountColumn, $block]) {
            $ordinals = [];
            foreach ($sheet->rowsWith($labelColumn, $amountColumn) as $row) {
                if ($row < 2) {
                    continue;
                }
                $line = $this->readLine($sheet, $month, $precision, $findings, $block, $row, $labelColumn, $amountColumn, $ordinals, $overrides);
                if (null !== $line) {
                    $lines[] = $line;
                }
            }
        }

        return new ParsedMonth($month, $sheet->name, $lines, $this->readAccounts($sheet, $precision, $findings));
    }

    private function hasExpectedHeader(Worksheet $sheet, Findings $findings): bool
    {
        $valid = true;
        foreach ([...self::PREFIX_HEADERS, ...self::EXACT_HEADERS] as $column => $expected) {
            $key = LabelKey::normalize($sheet->cell($column, 1)?->labelText() ?? '');
            $matches = isset(self::PREFIX_HEADERS[$column]) ? str_starts_with($key, $expected) : $key === $expected;
            if (!$matches) {
                $findings->add(ReasonCode::UNKNOWN_LAYOUT, $sheet->name, $column.'1');
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * @param array<string, int> $ordinals movements already read per label key in this block
     */
    private function readLine(
        Worksheet $sheet,
        int $month,
        int $precision,
        Findings $findings,
        Block $block,
        int $row,
        string $labelColumn,
        string $amountColumn,
        array &$ordinals,
        MonthCellOverrides $overrides,
    ): ?MovementLine {
        $rawLabel = $sheet->cell($labelColumn, $row)?->labelText();
        $labelKey = null === $rawLabel ? '' : LabelKey::normalize($rawLabel);
        $labelCell = $labelColumn.$row;
        $amountCell = $amountColumn.$row;

        if ($overrides->isSkipped($amountCell)) {
            $findings->add(ReasonCode::SKIPPED_BY_MAPPING, $sheet->name, $amountCell);

            return null;
        }

        $cached = $overrides->isCached($amountCell);
        $reading = $cached
            ? $this->amounts->readCached($sheet->cell($amountColumn, $row), $precision)
            : $this->amounts->read($sheet->cell($amountColumn, $row), $precision);
        if ($cached && !$reading->empty && !$reading->isRejected()) {
            $findings->warnCachedValueUsed($sheet->name, $amountCell);
        }

        if (null === $rawLabel || '' === $labelKey) {
            if ($reading->empty) {
                return null;
            }
            $onlyZero = !$reading->isRejected() && [] === $reading->terms;
            $findings->add($onlyZero ? ReasonCode::ZERO_AMOUNT : ReasonCode::AMOUNT_WITHOUT_LABEL, $sheet->name, $amountCell);

            return null;
        }

        if ($reading->empty) {
            $findings->add(ReasonCode::LABEL_WITHOUT_AMOUNT, $sheet->name, $labelCell);

            return new MovementLine($block, $row, $rawLabel, $labelKey, $labelCell, $amountCell, [], null);
        }

        $rejection = $reading->rejection;
        if (null === $rejection && Block::INC === $block) {
            foreach ($reading->terms as $term) {
                if ($term->value->isNegative()) {
                    $rejection = ReasonCode::NEGATIVE_INCOME;
                    break;
                }
            }
        }
        if (null !== $rejection) {
            $findings->add($rejection, $sheet->name, $amountCell);

            return new MovementLine($block, $row, $rawLabel, $labelKey, $labelCell, $amountCell, [], $rejection);
        }

        foreach ($reading->excluded as $excluded) {
            $findings->add($excluded, $sheet->name, $amountCell);
        }
        if ($reading->floatArtifact) {
            $findings->warnFloatArtifact($sheet->name, $amountCell);
        }

        $movements = [];
        foreach ($reading->terms as $term) {
            $ordinal = ($ordinals[$labelKey] ?? 0) + 1;
            $ordinals[$labelKey] = $ordinal;
            $movements[] = new ParsedMovement($block, $rawLabel, $labelKey, $month, $sheet->name, $amountCell, $ordinal, $term->value, $term->isProduct, $term->factors);
        }

        return new MovementLine($block, $row, $rawLabel, $labelKey, $labelCell, $amountCell, $movements, null);
    }

    /**
     * @return list<AccountRow>
     */
    private function readAccounts(Worksheet $sheet, int $precision, Findings $findings): array
    {
        $accounts = [];
        foreach ($sheet->rowsWith('J') as $row) {
            if ($row < self::FIRST_ACCOUNT_ROW || $row > self::LAST_ACCOUNT_ROW) {
                continue;
            }
            $rawLabel = $sheet->cell('J', $row)?->labelText();
            $labelKey = null === $rawLabel ? '' : LabelKey::normalize($rawLabel);
            if (null === $rawLabel || '' === $labelKey) {
                continue;
            }

            $readings = [];
            foreach (['K', 'L'] as $column) {
                $reading = $this->amounts->readValuation($sheet->cell($column, $row), $precision);
                if ($reading->floatArtifact) {
                    $findings->warnFloatArtifact($sheet->name, $column.$row);
                }
                $readings[$column] = $reading;
            }

            $accounts[] = new AccountRow(
                $row, $rawLabel, $labelKey, 'J'.$row, $readings['K']->value, $readings['L']->value,
                $readings['K']->rejection, $readings['L']->rejection, $readings['K']->rounded, $readings['L']->rounded,
            );
        }

        return $accounts;
    }
}
