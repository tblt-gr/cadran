<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Amount;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\Formula\ConstantFormula;
use App\Module\Import\Domain\Formula\FormulaRejected;
use App\Module\Import\Domain\Formula\FormulaTerm;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Sheet\Cell;
use App\Module\Import\Domain\Sheet\CellKind;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns an amount cell into exact movements and checks them against the value
 * the spreadsheet stored.
 *
 * A formula must stay inside the constant grammar and its terms must sum to the
 * cached value, with one tolerance: the binary float artifact of a stored value,
 * at most 10^-(precision+1) away. A plain number is read from its stored text
 * and normalized under the same tolerance. Nothing here goes through a float.
 */
final class AmountCellReader
{
    /** Beyond this exponent a stored number is not a plausible amount, and expanding it would waste memory. */
    private const int MAX_EXPONENT = 60;

    /**
     * @param bool $roundProducts a purchase column: a product beyond the precision is rounded to its gross (see ConstantFormula)
     */
    public function read(?Cell $cell, int $precision, bool $roundProducts = false): AmountReading
    {
        if (null === $cell) {
            return AmountReading::empty();
        }

        if (null !== $cell->formula) {
            return $this->readFormula($cell, $precision, $roundProducts);
        }

        return $this->readStored($cell, $precision);
    }

    /**
     * Reads the cell as its stored value, whatever formula it holds, with the rules of a plain number. A formula
     * cell without a stored value is a `CACHED_VALUE_MISMATCH`.
     */
    public function readCached(?Cell $cell, int $precision): AmountReading
    {
        if (null === $cell) {
            return AmountReading::empty();
        }

        return $this->readStored($cell, $precision);
    }

    private function readStored(Cell $cell, int $precision): AmountReading
    {
        if ($cell->sharedFormulaChild) {
            return AmountReading::rejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        $balance = $this->readBalance($cell, $precision);
        if ($balance->empty) {
            return AmountReading::empty();
        }
        if (null === $balance->value) {
            return AmountReading::rejected($balance->rejection ?? ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        if (self::isZero($balance->value)) {
            return AmountReading::movements([], [ReasonCode::ZERO_AMOUNT], $balance->floatArtifact);
        }

        return AmountReading::movements([new FormulaTerm($balance->value, false, [$balance->value])], [], $balance->floatArtifact);
    }

    /**
     * Reads the stored value of a cell as one exact figure, ignoring any
     * formula: balances are formulas in most years and their cached value is
     * what the owner saw. Unlike a movement, zero is a legitimate figure.
     */
    public function readBalance(?Cell $cell, int $precision): BalanceReading
    {
        return $this->readFigure($cell, $precision, false);
    }

    /**
     * Like a balance, but for a market value that legitimately has more decimals
     * than the asset (a quantity times a price): beyond the float-artifact
     * tolerance it is rounded half-even to the precision and flagged `rounded`
     * instead of being rejected.
     */
    public function readValuation(?Cell $cell, int $precision): BalanceReading
    {
        return $this->readFigure($cell, $precision, true);
    }

    private function readFigure(?Cell $cell, int $precision, bool $roundBeyondPrecision): BalanceReading
    {
        if (null === $cell || $cell->sharedFormulaChild && null === $cell->value) {
            return BalanceReading::empty();
        }

        if (CellKind::TEXT === $cell->kind) {
            return null === $cell->trimmedText()
                ? BalanceReading::empty()
                : BalanceReading::rejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        if (CellKind::NUMBER !== $cell->kind) {
            return BalanceReading::rejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        if (null === $cell->value) {
            return null === $cell->formula ? BalanceReading::empty() : BalanceReading::rejected(ReasonCode::CACHED_VALUE_MISMATCH);
        }

        $stored = $this->storedNumber($cell->value);
        if (false === $stored) {
            return BalanceReading::rejected(ReasonCode::PRECISION_EXCEEDED);
        }
        if (null === $stored) {
            return BalanceReading::rejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        $nearest = $stored->toScale(max(0, $precision), RoundingMode::HalfEven);
        $difference = $stored->minus($nearest)->abs();
        $beyond = $difference->isGreaterThan($this->tolerance($precision));
        if ($beyond && !$roundBeyondPrecision) {
            return BalanceReading::rejected(ReasonCode::PRECISION_EXCEEDED);
        }

        try {
            $value = DecimalValue::fromString((string) $nearest);
        } catch (\InvalidArgumentException) {
            return BalanceReading::rejected(ReasonCode::PRECISION_EXCEEDED);
        }

        return BalanceReading::of($value, !$beyond && !$difference->isZero(), $beyond);
    }

    private function readFormula(Cell $cell, int $precision, bool $roundProducts): AmountReading
    {
        try {
            $terms = ConstantFormula::parse((string) $cell->formula, $precision, $roundProducts);
        } catch (FormulaRejected $rejected) {
            return AmountReading::rejected($rejected->reason);
        }

        $cached = CellKind::NUMBER === $cell->kind && null !== $cell->value ? $this->storedNumber($cell->value) : null;
        if (!$cached instanceof BigDecimal) {
            return AmountReading::rejected(ReasonCode::CACHED_VALUE_MISMATCH);
        }

        $sum = BigDecimal::zero();
        foreach ($terms as $term) {
            $sum = $sum->plus(($term->exact ?? $term->value)->toString());
        }
        $difference = $cached->minus($sum)->abs();
        if ($difference->isGreaterThan($this->tolerance($precision))) {
            return AmountReading::rejected(ReasonCode::CACHED_VALUE_MISMATCH);
        }

        $movements = [];
        $excluded = [];
        foreach ($terms as $term) {
            if (self::isZero($term->value)) {
                $excluded[] = ReasonCode::ZERO_AMOUNT;
                continue;
            }
            $movements[] = $term;
        }

        return AmountReading::movements($movements, $excluded, !$difference->isZero());
    }

    /**
     * The exact decimal of a stored `<v>` text: null when it is not a number,
     * false when its exponent makes it implausible as an amount.
     */
    private function storedNumber(string $text): BigDecimal|false|null
    {
        if (1 !== preg_match('/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[Ee][+-]?([0-9]+))?$/D', $text, $matches)) {
            return null;
        }
        if (isset($matches[1]) && (int) $matches[1] > self::MAX_EXPONENT) {
            return false;
        }

        return BigDecimal::of($text);
    }

    private function tolerance(int $precision): BigDecimal
    {
        return BigDecimal::ofUnscaledValue(1, $precision + 1);
    }

    private static function isZero(DecimalValue $value): bool
    {
        return BigDecimal::of($value->toString())->isZero();
    }
}
