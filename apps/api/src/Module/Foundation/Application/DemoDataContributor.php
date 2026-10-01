<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/** Extends the development demo without making its orchestrator depend on feature modules. */
interface DemoDataContributor
{
    public function seed(): void;
}
