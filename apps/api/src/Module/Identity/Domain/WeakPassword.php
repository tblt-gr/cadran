<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * The submitted password does not meet the account password policy. The message
 * never echoes the candidate value.
 */
final class WeakPassword extends \InvalidArgumentException
{
}
