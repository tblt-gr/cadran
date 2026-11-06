<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class BulkPreviewStale extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The preview no longer matches the rows, or has expired.');
    }
}
