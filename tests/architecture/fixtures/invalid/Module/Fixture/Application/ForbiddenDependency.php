<?php

declare(strict_types=1);

namespace App\Module\Fixture\Application;

use App\Module\Fixture\Infrastructure\ForbiddenAdapter;

final class ForbiddenDependency
{
    public function __construct(public ForbiddenAdapter $adapter)
    {
    }
}
