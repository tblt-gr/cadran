<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The first-run password flow ran before IDN-001 provisioned an owner. In a
 * correctly bootstrapped install this cannot happen.
 */
final class OwnerAccountNotProvisioned extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No owner account has been provisioned.');
    }
}
