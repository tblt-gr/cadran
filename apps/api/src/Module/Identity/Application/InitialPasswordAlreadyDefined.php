<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The one-time first-run password flow was attempted after a password already
 * existed. Also raised when a concurrent request wins the compare-and-set.
 */
final class InitialPasswordAlreadyDefined extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account password has already been defined.');
    }
}
