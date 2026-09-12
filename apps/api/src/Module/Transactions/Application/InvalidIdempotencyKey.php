<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

final class InvalidIdempotencyKey extends \InvalidArgumentException
{
    public const string INVALID = 'invalid';
    public const string REQUIRED = 'required';

    public function __construct(public readonly string $ruleCode)
    {
        parent::__construct($ruleCode);
    }
}
