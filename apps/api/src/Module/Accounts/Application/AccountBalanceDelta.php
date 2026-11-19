<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * A signed change a write brings to the day-end balance of one account: an addition is the
 * new movement, a removal is the old one negated. `$field` names the form input to blame.
 */
final readonly class AccountBalanceDelta
{
    public const string FIELD_AMOUNT = 'amount';
    public const string FIELD_SOURCE_AMOUNT = 'sourceAmount';

    public function __construct(
        public string $accountId,
        public \DateTimeImmutable $day,
        public DecimalValue $amount,
        public string $field = self::FIELD_AMOUNT,
    ) {
    }
}
