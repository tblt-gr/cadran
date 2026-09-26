<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

final class MetricPolicyDefinitionExists extends \RuntimeException
{
    public function __construct(public readonly int $existingVersion)
    {
        parent::__construct('A metric policy with the same excluded account kinds already exists.');
    }
}
