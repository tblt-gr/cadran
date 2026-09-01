<?php

declare(strict_types=1);

namespace App\Module\Foundation\Domain;

/**
 * The literal is not a canonical decimal string. The message never quotes the
 * rejected literal: it can be a financial amount.
 */
final class MalformedDecimal extends \InvalidArgumentException
{
}
