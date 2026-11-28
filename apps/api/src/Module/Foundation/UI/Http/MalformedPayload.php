<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/**
 * A decoded body that is not the declared shape; controllers answer it as a client error.
 * Only the UI layer throws it, so an internal inconsistency can never be mistaken for one.
 */
final class MalformedPayload extends \DomainException
{
}
