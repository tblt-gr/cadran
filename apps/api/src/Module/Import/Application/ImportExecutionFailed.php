<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\ReasonCode;

/**
 * A use case refused one record while the year was being written. The whole
 * year was rolled back. It names the sheet, the cell and, when the refusal has
 * a reason code, that code; otherwise the short class name of the refusal. It
 * never quotes the message of the cause, which can hold a label or an amount.
 */
final class ImportExecutionFailed extends \RuntimeException
{
    public function __construct(
        public readonly ?ReasonCode $reason,
        public readonly ?string $sheet,
        public readonly ?string $cell,
        public readonly string $cause,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('A record of the year was refused.', 0, $previous);
    }
}
