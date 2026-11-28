<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

/** A decoded body that is not the declared shape; controllers answer it as unprocessable. */
final class MalformedPayload extends \UnexpectedValueException
{
}
