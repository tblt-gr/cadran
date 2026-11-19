<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InteractiveWriteContext;
use Symfony\Component\HttpFoundation\RequestStack;

/** A write is interactive exactly when it runs inside an HTTP request. */
final readonly class HttpInteractiveWriteContext implements InteractiveWriteContext
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function isInteractive(): bool
    {
        return null !== $this->requests->getMainRequest();
    }
}
