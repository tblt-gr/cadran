<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/** A requested value for one optional field; null clears it. An absent change is a null property elsewhere. */
final readonly class BulkFieldChange
{
    public function __construct(public ?string $value)
    {
    }
}
