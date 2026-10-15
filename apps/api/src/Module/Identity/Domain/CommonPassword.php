<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * The submitted password has an acceptable length but is one of the most common passwords. Kept apart
 * from the length refusal so the interface can tell the owner which rule failed.
 */
final class CommonPassword extends WeakPassword
{
}
