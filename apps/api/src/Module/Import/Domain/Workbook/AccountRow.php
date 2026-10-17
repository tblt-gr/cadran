<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Import\Domain\ReasonCode;

/**
 * A row of the account table of a month sheet. `opening` is column K (balance
 * at the end of the previous month) and `closing` column L, both read from the
 * cached values; an empty cell is null, never zero.
 *
 * A reading problem of K or L is recorded here, not reported: the planner raises
 * it only for a row it uses (a portfolio account takes its values from its own
 * sheet). `*Rounded` means the value had more decimals than the asset precision
 * and was rounded half-even: a warning for a snapshots account, a rejection for
 * a transactions account.
 */
final readonly class AccountRow
{
    public function __construct(
        public int $row,
        public string $rawLabel,
        public string $labelKey,
        public string $labelCell,
        public ?DecimalValue $opening,
        public ?DecimalValue $closing,
        public ?ReasonCode $openingProblem = null,
        public ?ReasonCode $closingProblem = null,
        public bool $openingRounded = false,
        public bool $closingRounded = false,
    ) {
    }
}
