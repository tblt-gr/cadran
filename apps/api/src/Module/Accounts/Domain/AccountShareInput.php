<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * The facts the share calculator needs about one account. The value is the
 * stored (positive) figure; the sign comes from the account kind.
 */
final readonly class AccountShareInput
{
    /**
     * @param list<string> $tagGroupIds
     */
    public function __construct(
        public string $accountId,
        public bool $includeInNetWorth,
        public int $netWorthSign,
        public ?string $primaryGroupId,
        public array $tagGroupIds,
        public ?DecimalValue $value,
    ) {
    }
}
