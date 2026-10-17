<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

use App\Module\Import\Domain\Block;

/**
 * The validated mapping file: the entry point of the planner for everything
 * that depends on the owner's own vocabulary. Every lookup takes a normalized
 * label key ({@see \App\Module\Import\Domain\LabelKey}) and returns null for a
 * label that is absent or still `null` in the file; the planner reports
 * `UNMAPPED_LABEL` or `UNMAPPED_ACCOUNT` for a null met in the imported year.
 * Names (accounts, categories) are Cadran labels, resolved by exact match
 * against the workspace at planning time.
 */
final readonly class ImportMapping
{
    /**
     * @param array<string, ?string>      $accounts               workbook account label key => Cadran account label
     * @param array<string, ?LineMapping> $income
     * @param array<string, ?LineMapping> $fixed
     * @param array<string, ?LineMapping> $variable
     * @param array<string, ?string>      $savings                savings label key => Cadran account label
     * @param array<string, string>       $ignore                 label key => reason
     * @param array<string, list<bool>>   $quantityFirstOverrides `YYYY-MM` => per-term flags
     */
    public function __construct(
        public ?string $mainAccount,
        private array $accounts,
        private array $income,
        private array $fixed,
        private array $variable,
        private array $savings,
        private ?PortfolioMapping $pea,
        private ?PortfolioMapping $ctoTr,
        private array $ignore,
        private array $quantityFirstOverrides,
        private CellOverrides $cellOverrides = new CellOverrides(),
    ) {
    }

    public function accountFor(string $labelKey): ?string
    {
        return $this->accounts[$labelKey] ?? null;
    }

    public function savingsTarget(string $labelKey): ?string
    {
        return $this->savings[$labelKey] ?? null;
    }

    /** The mapping of an `INC`, `FIX` or `VAR` label; null when absent or still null. */
    public function lineFor(Block $block, string $labelKey): ?LineMapping
    {
        return $this->section($block)[$labelKey] ?? null;
    }

    /** Whether the file lists the label at all, mapped or not (an unlisted label is new to the file). */
    public function mentions(Block $block, string $labelKey): bool
    {
        return array_key_exists($labelKey, $this->section($block));
    }

    public function isIgnored(string $labelKey): bool
    {
        return isset($this->ignore[$labelKey]);
    }

    public function ignoreReason(string $labelKey): ?string
    {
        return $this->ignore[$labelKey] ?? null;
    }

    /** `PEA` for the PEA sheet, `CTO` for the CTO_TR sheet. */
    public function portfolio(Block $kind): ?PortfolioMapping
    {
        return match ($kind) {
            Block::PEA => $this->pea,
            Block::CTO => $this->ctoTr,
            default => null,
        };
    }

    public function cellOverrides(): CellOverrides
    {
        return $this->cellOverrides;
    }

    /**
     * @return list<bool>|null
     */
    public function peaQuantityFirst(int $year, int $month): ?array
    {
        return $this->quantityFirstOverrides[sprintf('%04d-%02d', $year, $month)] ?? null;
    }

    /**
     * @return array<string, ?LineMapping>
     */
    private function section(Block $block): array
    {
        return match ($block) {
            Block::INC => $this->income,
            Block::FIX => $this->fixed,
            Block::VAR => $this->variable,
            default => [],
        };
    }
}
