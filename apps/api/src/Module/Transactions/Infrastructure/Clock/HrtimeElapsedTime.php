<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Clock;

use App\Module\Transactions\Application\Categorization\ElapsedTime;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ElapsedTime::class)]
final readonly class HrtimeElapsedTime implements ElapsedTime
{
    public function nanoseconds(): int
    {
        return hrtime(true);
    }
}
