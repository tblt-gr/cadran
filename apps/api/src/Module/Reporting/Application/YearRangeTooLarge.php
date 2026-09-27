<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** More than 15 years requested (`to - from > 14`). */
final class YearRangeTooLarge extends \DomainException
{
}
