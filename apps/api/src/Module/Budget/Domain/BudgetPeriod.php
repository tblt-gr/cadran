<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain;

/**
 * The bounded calendar range a budget plan covers: one month or one calendar
 * year. Stored on the plan as a first-of-period date, never as an open range,
 * so the plan's period is comparable and unique per workspace.
 */
final readonly class BudgetPeriod
{
    public const int MIN_YEAR = 1900;
    public const int MAX_YEAR = 2999;

    private function __construct(
        public BudgetPeriodType $type,
        private int $year,
        private int $month,
    ) {
    }

    public static function month(int $year, int $month): self
    {
        self::assertYear($year);
        if ($month < 1 || $month > 12) {
            throw new InvalidBudgetPeriod('A budget month must be between 1 and 12.');
        }

        return new self(BudgetPeriodType::MONTH, $year, $month);
    }

    public static function year(int $year): self
    {
        self::assertYear($year);

        return new self(BudgetPeriodType::YEAR, $year, 1);
    }

    /** The canonical first-of-period date, as stored on the plan row. */
    public static function fromDate(BudgetPeriodType $type, \DateTimeImmutable $firstDay): self
    {
        return BudgetPeriodType::MONTH === $type
            ? self::month((int) $firstDay->format('Y'), (int) $firstDay->format('n'))
            : self::year((int) $firstDay->format('Y'));
    }

    public static function fromKey(BudgetPeriodType $type, string $key): self
    {
        if (BudgetPeriodType::MONTH === $type) {
            if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $key, $parts)) {
                throw new InvalidBudgetPeriod('A monthly budget period is written YYYY-MM.');
            }

            return self::month((int) $parts[1], (int) $parts[2]);
        }

        if (1 !== preg_match('/^(\d{4})$/D', $key, $parts)) {
            throw new InvalidBudgetPeriod('An annual budget period is written YYYY.');
        }

        return self::year((int) $parts[1]);
    }

    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->year, $this->month), new \DateTimeZone('UTC'));
    }

    public function lastDay(): \DateTimeImmutable
    {
        if (BudgetPeriodType::MONTH === $this->type) {
            return $this->firstDay()->modify('last day of this month');
        }

        return new \DateTimeImmutable(sprintf('%04d-12-31', $this->year), new \DateTimeZone('UTC'));
    }

    public function key(): string
    {
        return BudgetPeriodType::MONTH === $this->type
            ? sprintf('%04d-%02d', $this->year, $this->month)
            : sprintf('%04d', $this->year);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->year === $other->year && $this->month === $other->month;
    }

    private static function assertYear(int $year): void
    {
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidBudgetPeriod(sprintf('A budget period year must be between %d and %d.', self::MIN_YEAR, self::MAX_YEAR));
        }
    }
}
