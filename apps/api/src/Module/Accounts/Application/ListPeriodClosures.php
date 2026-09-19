<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Application\CallerWorkspace;

/** The closures of one year, reopened ones included: the history a reviewer reads. */
final readonly class ListPeriodClosures
{
    public function __construct(private CallerWorkspace $caller, private PeriodClosureRepository $closures)
    {
    }

    /** @return list<PeriodClosure> */
    public function __invoke(int $year): array
    {
        if ($year < CalendarMonth::MIN_YEAR || $year > CalendarMonth::MAX_YEAR) {
            throw new InvalidPeriodClosureInput('The year is outside its bounds.');
        }

        return $this->closures->listForYear($this->caller->resolve(), $year);
    }
}
