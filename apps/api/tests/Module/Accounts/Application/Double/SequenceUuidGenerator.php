<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Foundation\Domain\UuidGenerator;

final class SequenceUuidGenerator implements UuidGenerator
{
    private int $sequence = 0;

    public function generate(): string
    {
        ++$this->sequence;

        return sprintf('00000000-0000-7000-8000-%012d', $this->sequence);
    }
}
