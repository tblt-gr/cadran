<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * The visible tail of a banking or contract identifier, such as `4821`.
 *
 * A user recognises one account from another by the last digits printed on
 * their statement, so the application stores that tail and nothing else. The
 * bound is the security control: eight characters cannot hold an IBAN, a card
 * number or a contract reference, so a full identifier can never be pasted in
 * and then read back from the interface, an export or a log.
 */
final readonly class MaskedIdentifier implements \Stringable
{
    public const int MAX_LENGTH = 8;

    private const string PATTERN = '/^[A-Z0-9]{2,8}$/D';

    private function __construct(private string $suffix)
    {
    }

    public static function fromString(string $suffix): self
    {
        if (1 !== preg_match(self::PATTERN, $suffix)) {
            throw new InvalidAccount(sprintf('A masked identifier is 2 to %d uppercase letters or digits, never a full identifier.', self::MAX_LENGTH));
        }

        return new self($suffix);
    }

    public function toString(): string
    {
        return $this->suffix;
    }

    public function __toString(): string
    {
        return $this->suffix;
    }
}
