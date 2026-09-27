<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** `from > to`, or either bound outside 1900..the current workspace year. */
final class InvalidYearRange extends \DomainException
{
}
