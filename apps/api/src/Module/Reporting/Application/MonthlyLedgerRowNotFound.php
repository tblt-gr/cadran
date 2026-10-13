<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/** The category or account of a ledger drill-down does not exist in the caller workspace. */
final class MonthlyLedgerRowNotFound extends \DomainException
{
}
