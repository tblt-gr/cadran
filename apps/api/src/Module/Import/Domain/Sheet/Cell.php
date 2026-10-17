<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Sheet;

/**
 * One stored cell: the cached value as text (the stored `<v>` for a number, the
 * resolved string for text) and the formula text when there is one. A number is
 * never converted to a binary float here.
 *
 * A shared-formula child carries no text of its own; such cells only exist in
 * derived areas and are never valid amount inputs.
 */
final readonly class Cell
{
    public function __construct(
        public CellKind $kind,
        public ?string $value,
        public ?string $formula = null,
        public bool $sharedFormulaChild = false,
    ) {
    }

    public static function number(string $storedValue, ?string $formula = null): self
    {
        return new self(CellKind::NUMBER, $storedValue, $formula);
    }

    public static function text(string $value, ?string $formula = null): self
    {
        return new self(CellKind::TEXT, $value, $formula);
    }

    public static function boolean(bool $value): self
    {
        return new self(CellKind::BOOLEAN, $value ? '1' : '0');
    }

    public static function error(string $code): self
    {
        return new self(CellKind::ERROR, $code);
    }

    /** A formula with no cached value, as written by a library that never recalculated. */
    public static function formulaWithoutValue(string $formula): self
    {
        return new self(CellKind::NUMBER, null, $formula);
    }

    public static function sharedFormulaChild(?string $storedValue): self
    {
        return new self(CellKind::NUMBER, $storedValue, null, true);
    }

    /** The text of a text cell with surrounding whitespace removed, or null when it holds no text. */
    public function trimmedText(): ?string
    {
        if (CellKind::TEXT !== $this->kind || null === $this->value) {
            return null;
        }
        $trimmed = trim($this->value);

        return '' === $trimmed ? null : $trimmed;
    }

    /** The cell read as a label: its text, or the stored text of a number (a label such as `2024`). */
    public function labelText(): ?string
    {
        if (CellKind::NUMBER === $this->kind) {
            return null === $this->value || '' === trim($this->value) ? null : trim($this->value);
        }

        return $this->trimmedText();
    }
}
