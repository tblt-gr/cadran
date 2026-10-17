<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Formula;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Import\Domain\ExactAmount;
use App\Module\Import\Domain\ReasonCode;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Parses an amount cell formula made only of constants, by the grammar.
 *
 *   expr   := term (("+" | "-") term)*
 *   term   := factor (("*" | "/") factor)*
 *   factor := ["-"] (number | "(" expr ")")
 *   number := digits ["." digits]
 *
 * and evaluates it with exact decimals. The text is never handed to an
 * evaluator: it is read character by character against this grammar, so a
 * formula can only ever be arithmetic on constants. Each top-level term is one
 * movement ({@see FormulaTerm}).
 */
final class ConstantFormula
{
    private const int MAX_LENGTH = 1000;
    private const int MAX_DEPTH = 32;

    private int $position = 0;
    private int $depth = 0;

    private function __construct(private readonly string $text)
    {
    }

    /**
     * With `$roundProducts` (a purchase column), a product of two factors whose
     * exact value has more decimals than the precision keeps its factors exact
     * and is rounded half-even to the precision: its gross. The factors must
     * still fit the instrument quantity and price precisions.
     *
     * @return non-empty-list<FormulaTerm>
     *
     * @throws FormulaRejected
     */
    public static function parse(string $formula, int $precision, bool $roundProducts = false): array
    {
        $trimmed = trim($formula, ' ');
        $text = str_starts_with($trimmed, '=') ? substr($trimmed, 1) : $trimmed;

        if (strlen($text) > self::MAX_LENGTH) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        self::refuseReferencesAndFunctions($text);

        $parser = new self($text);
        try {
            $terms = $parser->topLevel();
        } catch (\InvalidArgumentException) {
            // A product or sum that no longer fits the exact decimal storage width.
            throw new FormulaRejected(ReasonCode::PRECISION_EXCEEDED);
        }

        $fitted = [];
        foreach ($terms as [$value, $isProduct, $factors]) {
            try {
                $fitted[] = new FormulaTerm(ExactAmount::fit($value, $precision), $isProduct, $factors);
            } catch (\InvalidArgumentException) {
                if (!$roundProducts || !$isProduct || 2 !== count($factors) || !self::factorsFitInstrument($factors)) {
                    throw new FormulaRejected(ReasonCode::PRECISION_EXCEEDED);
                }
                $fitted[] = new FormulaTerm(ExactDecimal::round($value, $precision, RoundingMode::HALF_EVEN), true, $factors, $value);
            }
        }

        return $fitted;
    }

    /**
     * Quantity precision 8, price precision 4 (the portfolio instrument limits). A single whole factor is the
     * quantity, as the planner reads it; otherwise either side may be the quantity.
     *
     * @param list<DecimalValue> $factors
     */
    private static function factorsFitInstrument(array $factors): bool
    {
        [$first, $second] = [self::effectiveScale($factors[0]), self::effectiveScale($factors[1])];
        $firstWhole = 0 === $first;
        $secondWhole = 0 === $second;

        return match (true) {
            $firstWhole && !$secondWhole => $second <= 4,
            !$firstWhole && $secondWhole => $first <= 4,
            default => ($first <= 8 && $second <= 4) || ($second <= 8 && $first <= 4),
        };
    }

    private static function effectiveScale(DecimalValue $value): int
    {
        $literal = $value->toString();
        if (!str_contains($literal, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($literal, (int) strpos($literal, '.') + 1), '0'));
    }

    private static function refuseReferencesAndFunctions(string $text): void
    {
        if (1 === preg_match('/[A-Za-z_][A-Za-z0-9_.]*\(/', $text)) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        $reference = '/(?<![A-Za-z0-9_.])\$?[A-Za-z]{1,3}\$?[0-9]+(?![A-Za-z0-9_.])/';
        if (str_contains($text, '!') || 1 === preg_match($reference, $text)) {
            throw new FormulaRejected(ReasonCode::FORMULA_REFERENCE);
        }
    }

    /**
     * @return non-empty-list<array{DecimalValue, bool, list<DecimalValue>}>
     */
    private function topLevel(): array
    {
        $terms = [];
        $sign = 1;

        while (true) {
            $terms[] = $this->term($sign);
            $this->skipSpaces();

            $operator = $this->peek();
            if ('+' !== $operator && '-' !== $operator) {
                break;
            }
            ++$this->position;
            $sign = '-' === $operator ? -1 : 1;
        }

        if ($this->position < strlen($this->text)) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        return $terms;
    }

    /**
     * @return array{DecimalValue, bool, list<DecimalValue>}
     */
    private function term(int $sign): array
    {
        $factors = [$this->factor()];
        $value = $factors[0];
        $onlyProducts = true;

        while (true) {
            $this->skipSpaces();
            $operator = $this->peek();
            if ('*' !== $operator && '/' !== $operator) {
                break;
            }
            ++$this->position;
            $factor = $this->factor();
            $factors[] = $factor;

            if ('*' === $operator) {
                $value = ExactDecimal::multiply($value, $factor);
            } else {
                $onlyProducts = false;
                $value = $this->divideExactly($value, $factor);
            }
        }

        $isProduct = $onlyProducts && count($factors) > 1;

        return [ExactDecimal::signed($value, $sign), $isProduct, $isProduct ? $factors : [$value]];
    }

    private function factor(): DecimalValue
    {
        $this->skipSpaces();
        $negative = false;
        if ('-' === $this->peek()) {
            $negative = true;
            ++$this->position;
            $this->skipSpaces();
        }

        $next = $this->peek();
        if ('(' === $next) {
            ++$this->position;
            if (++$this->depth > self::MAX_DEPTH) {
                throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
            }
            $value = $this->group();
            --$this->depth;
        } elseif (null !== $next && ctype_digit($next)) {
            $value = $this->number();
        } else {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        return $negative ? ExactDecimal::negate($value) : $value;
    }

    private function group(): DecimalValue
    {
        $total = $this->term(1)[0];

        while (true) {
            $this->skipSpaces();
            $operator = $this->peek();
            if ('+' !== $operator && '-' !== $operator) {
                break;
            }
            ++$this->position;
            $next = $this->term('-' === $operator ? -1 : 1)[0];
            $total = ExactDecimal::add($total, $next);
        }

        if (')' !== $this->peek()) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        ++$this->position;

        return $total;
    }

    private function number(): DecimalValue
    {
        if (1 !== preg_match('/\G([0-9]+)(?:\.([0-9]+))?/', $this->text, $matches, 0, $this->position)) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        $this->position += strlen($matches[0]);

        // `007` is a valid number in a formula but not a canonical decimal literal.
        $integer = ltrim($matches[1], '0');
        $literal = ('' === $integer ? '0' : $integer).(isset($matches[2]) ? '.'.$matches[2] : '');

        return DecimalValue::fromString($literal);
    }

    /** Division is accepted only when its quotient is exact within the storage scale. */
    private function divideExactly(DecimalValue $numerator, DecimalValue $denominator): DecimalValue
    {
        try {
            $quotient = BigDecimal::of($numerator->toString())->dividedByExact(BigDecimal::of($denominator->toString()));
        } catch (MathException) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }
        if ($quotient->getScale() > DecimalValue::MAX_SCALE) {
            throw new FormulaRejected(ReasonCode::FORMULA_NOT_SUPPORTED);
        }

        return DecimalValue::fromString((string) $quotient);
    }

    private function peek(): ?string
    {
        return $this->text[$this->position] ?? null;
    }

    private function skipSpaces(): void
    {
        while (' ' === $this->peek()) {
            ++$this->position;
        }
    }
}
