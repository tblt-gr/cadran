<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application\Double;

use App\Module\Accounts\Application\InteractiveWriteContext;

/** Every write is a person's: the balance floor is judged. */
final class AlwaysInteractive implements InteractiveWriteContext
{
    public function isInteractive(): bool
    {
        return true;
    }
}
