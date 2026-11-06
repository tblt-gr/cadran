<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class BulkTargetAccountNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The target account does not exist in the workspace or is archived.');
    }
}
