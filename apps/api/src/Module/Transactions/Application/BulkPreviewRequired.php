<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class BulkPreviewRequired extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A move needs the token of a preview of the same scope and changes.');
    }
}
