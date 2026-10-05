<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

/**
 * The only trace of a contract number: its last four characters. The full number is read once, here, and
 * dropped; no message below ever repeats what was submitted.
 */
final readonly class ContractNumberSuffix
{
    public const int MIN_LENGTH = 4;
    public const int MAX_LENGTH = 34;

    private function __construct(public string $value)
    {
    }

    public static function fromNumber(string $number): self
    {
        $length = strlen($number);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || 1 !== preg_match('/^[A-Za-z0-9 -]+$/D', $number)) {
            throw new InvalidLifeInsurance('The contract number is not valid.', 'invalid_contract_field');
        }
        $compact = strtoupper(str_replace([' ', '-'], '', $number));
        if (strlen($compact) < self::MIN_LENGTH) {
            throw new InvalidLifeInsurance('The contract number is not valid.', 'invalid_contract_field');
        }

        return new self(substr($compact, -self::MIN_LENGTH));
    }

    public static function fromStored(string $suffix): self
    {
        if (1 !== preg_match('/^[A-Z0-9]{4}$/D', $suffix)) {
            throw new InvalidLifeInsurance('The stored contract number suffix is not valid.', 'invalid_contract_field');
        }

        return new self($suffix);
    }

    public function masked(): string
    {
        return '•••• '.$this->value;
    }
}
