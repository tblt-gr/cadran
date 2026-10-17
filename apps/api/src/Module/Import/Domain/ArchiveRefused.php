<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/** A workbook archive failed a security or well-formedness control: the whole file is refused. */
final class ArchiveRefused extends \RuntimeException
{
    public function __construct(public readonly ArchiveRefusal $cause)
    {
        parent::__construct(sprintf('%s: %s.', ReasonCode::ARCHIVE_REFUSED->value, $cause->value));
    }
}
