<?php

declare(strict_types=1);

namespace App\Module\Import\Application\Planning;

use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Application\ImportReferences;
use App\Module\Import\Domain\Issue;
use App\Module\Import\Domain\Mapping\ImportMapping;
use App\Module\Import\Domain\Mapping\InstrumentMapping;
use App\Module\Import\Domain\Plan\PlannedInstrument;
use App\Module\Import\Domain\Plan\PlannedStep;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\Warning;

/**
 * The mutable state of planning one workbook, shared by the planners of each
 * block: the issues found so far, the steps in execution order, and the
 * workspace lookups memoized so each failure is reported once.
 */
final class PlanningState
{
    private const string ASSET = 'EUR';
    private const int MAX_LABEL_LENGTH = 140;

    /** @var list<Issue> */
    private array $issues;

    /** @var list<PlannedStep> */
    private array $steps = [];

    /** @var array<string, true> */
    private array $reported = [];

    /** @var array<string, ?ImportAccount> */
    private array $accounts = [];

    /** @var array<string, true> */
    private array $guardedDays = [];

    /** @var array<string, string> */
    private array $existingInstruments = [];

    /** @var array<string, PlannedInstrument> */
    private array $instrumentsToCreate = [];

    /** @var list<Warning> */
    private array $warnings = [];

    private int $ignoredLines = 0;
    private ?\DateTimeImmutable $today = null;

    /**
     * @param list<Issue> $readingIssues the issues found while reading the workbook, which come first
     */
    public function __construct(
        public readonly ImportReferences $references,
        public readonly ImportMapping $mapping,
        public readonly int $year,
        array $readingIssues,
    ) {
        $this->issues = $readingIssues;
    }

    /**
     * @param bool|null $rejection overrides the default of the code (an unfinished month is an exclusion as a whole sheet)
     */
    public function reject(ReasonCode $code, ?string $sheet, ?string $cell, ?bool $rejection = null): void
    {
        $key = $code->value.'|'.$sheet.'|'.$cell.'|'.var_export($rejection, true);
        if (isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;
        $this->issues[] = new Issue($code, $sheet, $cell, $rejection);
    }

    public function warn(Warning $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function warnValueRounded(string $sheet, string $cell): void
    {
        $this->warn(new Warning(Warning::VALUE_ROUNDED, $sheet, $cell));
    }

    /**
     * @return list<Warning>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function today(): \DateTimeImmutable
    {
        return $this->today ??= $this->references->today();
    }

    /**
     * The day every record of a month sheet is booked: its last day, or today for the month in progress.
     * The opening records are not concerned: they keep the last day of the previous month.
     */
    public function bookingDay(int $month): string
    {
        $last = self::lastDayOf($this->year, $month);
        $today = $this->today()->format('Y-m-d');

        return $last > $today ? $today : $last;
    }

    /** Appends a step booked on `$day`, after checking that day against the closed periods and the workspace calendar. */
    public function emit(PlannedStep $step, string $day): void
    {
        $this->guardDay($day, $step->sheet());
        $this->steps[] = $step;
    }

    public function countIgnoredLine(): void
    {
        ++$this->ignoredLines;
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<PlannedStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function ignoredLines(): int
    {
        return $this->ignoredLines;
    }

    /**
     * @return list<PlannedInstrument>
     */
    public function instrumentsToCreate(): array
    {
        return array_values($this->instrumentsToCreate);
    }

    /**
     * @return array<string, string>
     */
    public function existingInstruments(): array
    {
        return $this->existingInstruments;
    }

    /** The main account, reported once as unmapped when the mapping or the workspace lacks it. */
    public function mainAccount(): ?ImportAccount
    {
        return $this->account($this->mapping->mainAccount, null, null, false);
    }

    /**
     * Resolves a Cadran account label, reporting `UNMAPPED_ACCOUNT` at the given
     * location when it is null, unknown, not in EUR or a portfolio account where
     * only cash accounts are accepted.
     */
    public function account(?string $label, ?string $sheet, ?string $cell, bool $allowPortfolio): ?ImportAccount
    {
        $account = null === $label ? null : $this->lookup($label);
        if (null === $account || self::ASSET !== $account->assetCode || (!$allowPortfolio && $account->isPortfolio())) {
            $this->reject(ReasonCode::UNMAPPED_ACCOUNT, $sheet, $cell);

            return null;
        }

        return $account;
    }

    /** Rejects a record booked before its account was opened. */
    public function canBook(ImportAccount $account, string $day, string $sheet, ?string $cell): bool
    {
        if ($day < $account->openedOn) {
            $this->reject(ReasonCode::ACCOUNT_OPENED_AFTER_DATE, $sheet, $cell);

            return false;
        }

        return true;
    }

    /** Names the instrument of a mapping, to create when the workspace lacks it, and returns the name the steps carry. */
    public function useInstrument(InstrumentMapping $instrument): string
    {
        $name = $instrument->name;
        if (isset($this->existingInstruments[$name]) || isset($this->instrumentsToCreate[$name])) {
            return $name;
        }
        $existing = $this->references->instrument($name);
        if (null !== $existing) {
            $this->existingInstruments[$name] = $existing;
        } else {
            $this->instrumentsToCreate[$name] = new PlannedInstrument($name, $instrument->type, $instrument->isin, $instrument->ticker);
        }

        return $name;
    }

    public function isIgnored(string $labelKey): bool
    {
        if (!$this->mapping->isIgnored($labelKey)) {
            return false;
        }
        $this->countIgnoredLine();

        return true;
    }

    public static function lastDayOf(int $year, int $month): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
    }

    /** The last day of the month before the given one, possibly in the previous year. */
    public static function lastDayBefore(int $year, int $month): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    }

    /** The raw label of a record: the workbook's text, cut to what a transaction accepts (140 characters, no trailing space). */
    public static function label(string $rawLabel): string
    {
        return rtrim(mb_substr($rawLabel, 0, self::MAX_LABEL_LENGTH));
    }

    public static function note(int $year, string $sheet, ?string $cell): string
    {
        return sprintf('Imported from workbook %d, %s%s', $year, $sheet, null === $cell ? '' : ' '.$cell);
    }

    private function lookup(string $label): ?ImportAccount
    {
        if (!array_key_exists($label, $this->accounts)) {
            $this->accounts[$label] = $this->references->account($label);
        }

        return $this->accounts[$label];
    }

    private function guardDay(string $day, string $sheet): void
    {
        // Reported once per day, on the first sheet that books on it (the month sheet comes before the portfolio sheets).
        if (isset($this->guardedDays[$day])) {
            return;
        }
        $this->guardedDays[$day] = true;

        if ([] !== $this->references->closedMonths([substr($day, 0, 7)])) {
            $this->reject(ReasonCode::PERIOD_CLOSED, $sheet, null);
        }
        if ($day > $this->today()->format('Y-m-d')) {
            // Not reachable while months are dated by bookingDay(): kept so a future booking can never be written.
            $this->reject(ReasonCode::MONTH_NOT_ENDED, $sheet, null, true);
        }
    }
}
