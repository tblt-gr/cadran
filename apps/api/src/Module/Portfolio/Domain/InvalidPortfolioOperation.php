<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

final class InvalidPortfolioOperation extends \DomainException
{
    /** @param array<string, string> $extensions problem members that help the client fix the input, never a secret */
    public function __construct(string $message, public readonly string $problemCode, public readonly array $extensions = [])
    {
        parent::__construct($message);
    }

    public static function field(string $code, string $field): self
    {
        return new self(sprintf('Operation field "%s": %s.', $field, $code), $code, ['field' => $field]);
    }
}
