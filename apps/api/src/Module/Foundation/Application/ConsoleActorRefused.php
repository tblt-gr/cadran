<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/** The console may only act as a provisioned, enabled owner; the message never echoes the email. */
final class ConsoleActorRefused extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No enabled owner was found: run the local setup first or check the email.');
    }
}
