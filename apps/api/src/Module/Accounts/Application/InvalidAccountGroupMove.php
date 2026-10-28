<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** The move names an anchor that is not an active sibling of the moved group. */
final class InvalidAccountGroupMove extends \DomainException
{
    public const string INVALID_ANCHOR = 'invalid_anchor';

    public function __construct(string $message, public readonly string $reason = self::INVALID_ANCHOR)
    {
        parent::__construct($message);
    }
}
