<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

final class GetFoundationStatus
{
    public function __invoke(): FoundationStatus
    {
        return new FoundationStatus(status: 'ready', apiVersion: 'v1');
    }
}
