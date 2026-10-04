<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\Performance;

use App\Module\Portfolio\Domain\InvalidPortfolioOperation;

/** The requested window of a performance figure: a month, a year or the whole life of the account. */
final readonly class PerformancePeriod
{
    private function __construct(
        public PerformanceScope $scope,
        public ?string $label,
        public ?\DateTimeImmutable $firstDay,
        public ?\DateTimeImmutable $lastDay,
    ) {
    }

    public static function of(?string $scopeLiteral, ?string $periodLiteral, \DateTimeImmutable $today, \DateTimeImmutable $openedOn): self
    {
        $scope = null === $scopeLiteral ? null : PerformanceScope::tryFrom($scopeLiteral);
        if (null === $scope) {
            throw new InvalidPortfolioOperation('The performance scope is MONTH, YEAR or INCEPTION.', 'invalid_performance_scope');
        }
        if (PerformanceScope::INCEPTION === $scope) {
            if (null !== $periodLiteral) {
                throw self::invalidPeriod();
            }

            return new self($scope, null, null, null);
        }
        $period = PerformanceScope::MONTH === $scope ? self::month($periodLiteral) : self::year($periodLiteral);
        [$first, $last] = $period;
        if ($first > $today || $last < $openedOn->modify('first day of this month')) {
            throw self::invalidPeriod();
        }

        return new self($scope, (string) $periodLiteral, $first, $last);
    }

    /** The day the start anchor stands for: the day before the period begins, null for the whole life. */
    public function startTarget(): ?\DateTimeImmutable
    {
        return $this->firstDay?->modify('-1 day');
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private static function month(?string $literal): array
    {
        if (null === $literal || 1 !== preg_match('/^([0-9]{4})-(0[1-9]|1[0-2])$/D', $literal)) {
            throw self::invalidPeriod();
        }
        $first = new \DateTimeImmutable($literal.'-01', new \DateTimeZone('UTC'));

        return [$first, $first->modify('last day of this month')];
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private static function year(?string $literal): array
    {
        if (null === $literal || 1 !== preg_match('/^[0-9]{4}$/D', $literal)) {
            throw self::invalidPeriod();
        }

        return [new \DateTimeImmutable($literal.'-01-01', new \DateTimeZone('UTC')), new \DateTimeImmutable($literal.'-12-31', new \DateTimeZone('UTC'))];
    }

    private static function invalidPeriod(): InvalidPortfolioOperation
    {
        return new InvalidPortfolioOperation('The performance period is a past or current month (YYYY-MM) or year (YYYY).', 'invalid_performance_period');
    }
}
